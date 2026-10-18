<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\Automation\Routine\RoutineIntakeRefused;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\ContentVersionService;
use App\Libraries\HtmlSanitizer;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Ingests marketing-blog drafts submitted by the Claude routine (Sonnet).
 *
 * The draft is stored as a blog item in `draft` and a routine submission; the
 * RoutineContentPipeline then has Gemini vet it — that vetting run is the
 * verification of record — and approves, covers and publishes it. A
 * `verification_report` from the routine is still accepted and kept for
 * reference, but it is no longer required and decides nothing.
 *
 * Idempotent by content key (content_base_key): re-sending a key returns the
 * existing item. A different key for a title or slug that already exists is
 * refused as a duplicate topic (409).
 */
class AutomationDraftIngestService
{
    private const MIN_WORDS = 300;

    private BaseConnection $db;
    private WorkBlockService $workBlocks;
    private RoutineSubmissions $submissions;

    public function __construct(?BaseConnection $db = null, ?WorkBlockService $workBlocks = null, ?RoutineSubmissions $submissions = null)
    {
        $this->db          = $db ?? Database::connect();
        $this->workBlocks  = $workBlocks ?? new WorkBlockService();
        $this->submissions = $submissions ?? new RoutineSubmissions($this->db);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string, submission_id:int, content_item_id:int, content_version_id:int, slug:string, existing?:bool, quota?:array<string,int>}
     * @throws \InvalidArgumentException on validation failure (message is safe for the API response)
     * @throws RoutineIntakeRefused      switched off (403), duplicate topic (409), quota used (429)
     */
    public function ingestBlogDraft(array $payload): array
    {
        // A rewrite of a queued item (GET v1/automation/regenerate/next) goes
        // onto the same item and slug instead of creating a new article.
        if (! empty($payload['regeneration_id'])) {
            return (new \App\Libraries\Content\Quality\RegenerationIngestService($this->db, $this->submissions))->ingest('blog', $payload);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 10) {
            throw new \InvalidArgumentException('title is required (10+ chars)');
        }

        $bodyHtml = (string) ($payload['body_html'] ?? '');
        if (trim($bodyHtml) === '') {
            throw new \InvalidArgumentException('body_html is required');
        }
        $bodyHtml = (new HtmlSanitizer())->purify($bodyHtml);

        $plain = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $words = str_word_count($plain);
        if ($words < self::MIN_WORDS) {
            throw new \InvalidArgumentException("body too short: {$words} words, need at least " . self::MIN_WORDS);
        }

        $contentKey = strtolower(trim((string) ($payload['content_base_key'] ?? $payload['content_key'] ?? '')));
        if ($contentKey === '' || preg_match('/^[a-z0-9][a-z0-9._-]{2,119}$/', $contentKey) !== 1) {
            throw new \InvalidArgumentException('content_base_key is required: 3-120 chars of a-z, 0-9, ".", "_" or "-" (e.g. blog-2026-10-gstr-3b-due-dates)');
        }
        $slugHint = trim((string) ($payload['seo']['slug'] ?? $payload['slug_hint'] ?? ''));

        $existing = $this->existingForKey($contentKey);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertNotDuplicate($title, $slugHint);
        $quota = $this->submissions->assertAccepting('blog');

        $slug       = $this->workBlocks->buildUniqueSlug($slugHint !== '' ? $slugHint : $title);
        $now        = date('Y-m-d H:i:s');
        $provenance = (array) ($payload['provenance'] ?? []);
        $report     = is_array($payload['verification_report'] ?? null) ? $payload['verification_report'] : null;

        $this->db->transStart();

        $this->db->table('reach_content_items')->insert([
            'uuid'               => $this->uuid(),
            'content_type'       => 'blog',
            'title'              => mb_substr($title, 0, 300),
            'slug'               => $slug,
            'content_base_key'   => $contentKey,
            'workflow_status'    => BlogStateMachine::DRAFT,
            'approval_status'    => 'pending',
            'created_actor_type' => 'system',
            'created_by_service' => 'reach:automation_ingest',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $contentItemId = (int) $this->db->insertID();

        $this->db->table('reach_content_blog_details')->insert([
            'content_item_id'        => $contentItemId,
            'portfolio_stream'       => $payload['portfolio_stream'] ?? null,
            'funnel_stage'           => $this->normalizeFunnelStage($payload['funnel_stage'] ?? null),
            'audience'               => $payload['audience'] ?? null,
            'search_intent'          => $payload['search_intent'] ?? null,
            'origin'                 => 'claude_routine',
            'last_verification_json' => $report !== null ? json_encode(['routine_report' => $report], JSON_UNESCAPED_SLASHES) : null,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);

        $version = (new ContentVersionService())->createVersion($contentItemId, [
            'title'           => $title,
            'summary'         => $payload['summary'] ?? null,
            'body_html'       => $bodyHtml,
            'body_markdown'   => $payload['body_markdown'] ?? null,
            'body_plain_text' => $plain,
        ], [
            'type'    => 'bot',
            'service' => 'reach:automation_ingest:' . mb_substr((string) ($provenance['generated_by_model'] ?? 'claude'), 0, 40),
        ], 'Claude routine draft (awaiting Gemini vetting)');
        $versionId = (int) $version['id'];

        // SEO + publication profiles (category/tags land on the profile).
        $this->workBlocks->ensurePublicationProfilesForItem($contentItemId);
        $this->applySeoAndProfile($contentItemId, $payload);

        // Link back to the content-base candidate when one exists.
        $this->db->table('reach_topic_candidates')
            ->where('content_base_key', $contentKey)
            ->where('content_item_id IS NULL', null, false)
            ->update(['content_item_id' => $contentItemId, 'status' => 'roadmap_selected', 'updated_at' => $now]);

        $submissionId = $this->submissions->create('blog', $contentItemId, $contentKey, array_filter([
            'product_facts'  => array_values(array_filter(array_map('strval', (array) ($payload['product_facts'] ?? [])))),
            'sources'        => array_values(array_filter(array_map('strval', (array) ($payload['sources'] ?? [])))),
            'cover_prompt'   => isset($payload['cover_prompt']) ? mb_substr((string) $payload['cover_prompt'], 0, 300) : null,
            'provenance'     => $provenance,
            'routine_run_id' => $provenance['routine_run_id'] ?? null,
            'words'          => $words,
        ], static fn ($v) => $v !== null && $v !== []));

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('ingest transaction failed');
        }

        \App\Libraries\AuditLogger::record('blog.automation_ingest', [
            'content_item_id' => $contentItemId,
            'submission_id'   => $submissionId,
            'origin'          => 'claude_routine',
            'generated_by'    => $provenance['generated_by_model'] ?? null,
            'routine_run_id'  => $provenance['routine_run_id'] ?? null,
            'words'           => $words,
        ]);

        return [
            'status'             => 'ingested',
            'submission_id'      => $submissionId,
            'content_item_id'    => $contentItemId,
            'content_version_id' => $versionId,
            'slug'               => $slug,
            'quota'              => ['daily_limit' => $quota['daily_limit'], 'used_today' => $quota['used_today'] + 1, 'remaining_today' => max(0, $quota['remaining_today'] - 1)],
        ];
    }

    /** @return array<string,mixed>|null */
    private function existingForKey(string $contentKey): ?array
    {
        $existing = $this->db->table('reach_content_items')
            ->select('id, slug, current_version_id')
            ->where('content_type', 'blog')
            ->where('content_base_key', $contentKey)
            ->get()->getRowArray()
            ?? $this->db->table('reach_content_items i')
                ->join('reach_topic_candidates c', 'c.content_item_id = i.id')
                ->select('i.id, i.slug, i.current_version_id')
                ->where('c.content_base_key', $contentKey)
                ->get()->getRowArray();
        if (! $existing) {
            return null;
        }

        $submission = $this->submissions->findByItem((int) $existing['id']);

        return [
            'status'             => 'already_ingested',
            'submission_id'      => (int) ($submission['id'] ?? 0),
            'content_item_id'    => (int) $existing['id'],
            'content_version_id' => (int) ($existing['current_version_id'] ?? 0),
            'slug'               => (string) $existing['slug'],
            'existing'           => true,
        ];
    }

    /** @throws RoutineIntakeRefused */
    private function assertNotDuplicate(string $title, string $slugHint): void
    {
        $normalized = self::normalizeTitle($title);
        $slug       = strtolower(trim($slugHint));

        $rows = [];
        if ($slug !== '') {
            $rows = $this->db->table('reach_content_items')
                ->select('id, title, slug, workflow_status')
                ->where('content_type', 'blog')
                ->where('deleted_at IS NULL', null, false)
                ->whereNotIn('workflow_status', [BlogStateMachine::REJECTED, BlogStateMachine::ARCHIVED])
                ->where('slug', $slug)
                ->get()->getResultArray();
        }

        // Punctuation- and case-insensitive title match over recent blogs
        // catches "GST: due dates" vs "GST due dates".
        if ($rows === []) {
            foreach ($this->db->table('reach_content_items')->select('id, title, slug, workflow_status')
                ->where('content_type', 'blog')->where('deleted_at IS NULL', null, false)
                ->whereNotIn('workflow_status', [BlogStateMachine::REJECTED, BlogStateMachine::ARCHIVED])
                ->orderBy('id', 'DESC')->limit(2000)->get()->getResultArray() as $row) {
                if (self::normalizeTitle((string) $row['title']) === $normalized) {
                    $rows[] = $row;
                    break;
                }
            }
        }

        // A topic waiting for its same-slug rewrite is not free for a new article.
        if ($rows === [] && \App\Libraries\Database\SchemaGuard::hasTable($this->db, 'reach_content_regenerations')) {
            foreach ($this->db->table('reach_content_regenerations')->select('id, content_item_id, title, slug')
                ->where('content_type', 'blog')->whereIn('status', ['queued', 'claimed'])->get()->getResultArray() as $regen) {
                if (($slug !== '' && $slug === $regen['slug']) || self::normalizeTitle((string) $regen['title']) === $normalized) {
                    throw new RoutineIntakeRefused(
                        'This topic is queued for a rewrite at its existing URL: fetch GET v1/automation/regenerate/next and submit it with regeneration_id ' . $regen['id'] . '.',
                        409,
                        ['regeneration_id' => (int) $regen['id'], 'existing_slug' => $regen['slug'], 'existing_content_item_id' => (int) $regen['content_item_id']],
                    );
                }
            }
        }

        if ($rows !== []) {
            throw new RoutineIntakeRefused(
                'Duplicate topic: an article with this title or slug already exists (' . $rows[0]['slug'] . '). Pick a different topic from GET v1/automation/blog-plan.',
                409,
                ['existing_slug' => $rows[0]['slug'], 'existing_content_item_id' => (int) $rows[0]['id']],
            );
        }
    }

    public static function normalizeTitle(string $title): string
    {
        $t = mb_strtolower($title);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function applySeoAndProfile(int $contentItemId, array $payload): void
    {
        $now = date('Y-m-d H:i:s');
        $seo = (array) ($payload['seo'] ?? []);

        $update = array_filter([
            'meta_title'       => isset($seo['meta_title']) ? mb_substr((string) $seo['meta_title'], 0, 120) : null,
            'meta_description' => isset($seo['meta_description']) ? mb_substr((string) $seo['meta_description'], 0, 320) : null,
            'primary_keyword'  => isset($seo['primary_keyword']) ? mb_substr((string) $seo['primary_keyword'], 0, 255) : null,
        ]);
        if ($update !== []) {
            $this->db->table('reach_content_seo_profiles')
                ->where('content_item_id', $contentItemId)
                ->update($update + ['updated_at' => $now]);
        }

        $profileUpdate = [];
        if (! empty($payload['category'])) {
            $profileUpdate['category'] = mb_substr((string) $payload['category'], 0, 100);
        }
        if (! empty($payload['tags']) && is_array($payload['tags'])) {
            $profileUpdate['tags_json'] = json_encode(array_values(array_map('strval', $payload['tags'])), JSON_UNESCAPED_SLASHES);
        }
        if ($profileUpdate !== []) {
            $this->db->table('reach_blog_publication_profiles')
                ->where('content_item_id', $contentItemId)
                ->update($profileUpdate + ['updated_at' => $now]);
        }
    }

    private function normalizeFunnelStage(mixed $stage): ?string
    {
        $stage = strtolower(trim((string) $stage));

        return in_array($stage, ['top', 'middle', 'bottom'], true) ? $stage : null;
    }

    private function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
