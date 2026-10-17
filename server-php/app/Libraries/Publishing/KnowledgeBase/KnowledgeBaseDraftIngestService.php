<?php

declare(strict_types=1);

namespace App\Libraries\Publishing\KnowledgeBase;

use App\Libraries\Automation\Routine\KbSteps;
use App\Libraries\Automation\Routine\LaunchApps;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\ContentVersionService;
use App\Libraries\HtmlSanitizer;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Claude-routine Knowledge Base ingestion. KB articles are written ONLY by the
 * routine (Sonnet, from the product's own code). A valid draft is stored as a
 * pending KB item plus a routine submission; RoutineContentPipeline then has
 * Gemini vet it (knowledge_base rubric — the run is the verification of
 * record), approves it under the SystemApprover and publishes it to
 * aicountly.com/help. KB items bypass the blog state machine entirely.
 * KB_AUTO_APPROVE is no longer consulted for routine items.
 */
class KnowledgeBaseDraftIngestService
{
    private const ARTICLE_TYPES = ['concept', 'how_to', 'troubleshooting', 'faq', 'release_guide', 'configuration', 'integration', 'reference', 'best_practice'];

    private BaseConnection $db;
    private RoutineSubmissions $submissions;

    public function __construct(?BaseConnection $db = null, ?RoutineSubmissions $submissions = null)
    {
        $this->db          = $db ?? Database::connect();
        $this->submissions = $submissions ?? new RoutineSubmissions($this->db);
    }

    /**
     * Today's writing plan for the routine: the overall quota, then per
     * product (launch apps first) the remaining per-product cadence and the
     * un-ingested topic seeds from content-base/knowledge-base/index.json.
     * Seeds are suggestions — the routine may propose its own topic for real
     * functionality it finds in the product's code, with its own content key.
     *
     * @return array<string,mixed>
     */
    public function plan(): array
    {
        $cadences = $this->db->table('reach_kb_product_cadence')
            ->where('enabled', true)
            ->orderBy('daily_quota', 'DESC')
            ->get()->getResultArray();

        $index     = (new \App\Libraries\Blog\ContentBaseService($this->db))->knowledgeBaseIndex();
        $byProduct = [];
        foreach ((array) ($index['products'] ?? []) as $productEntry) {
            $byProduct[(string) ($productEntry['product_slug'] ?? '')] = (array) ($productEntry['topics'] ?? []);
        }

        $todayByProduct = [];
        foreach ($this->db->query(
            "SELECT pr.slug, COUNT(*) AS c
             FROM reach_routine_submissions s
             JOIN reach_kb_publication_profiles p ON p.content_item_id = s.content_item_id
             JOIN reach_products pr ON pr.id = p.product_id
             WHERE s.content_type = 'knowledge_base' AND s.status <> 'rejected' AND s.created_at >= ?
             GROUP BY pr.slug",
            [RoutineSubmissions::istMidnight()]
        )->getResultArray() as $row) {
            $todayByProduct[(string) $row['slug']] = (int) $row['c'];
        }

        $launch = LaunchApps::slugs();
        usort($cadences, static function (array $a, array $b) use ($launch): int {
            $ia = array_search($a['product_slug'], $launch, true);
            $ib = array_search($b['product_slug'], $launch, true);

            return [$ia === false ? 99 : $ia, -(int) $a['daily_quota']] <=> [$ib === false ? 99 : $ib, -(int) $b['daily_quota']];
        });

        $repos = array_column(LaunchApps::APPS, 'repo', 'product_slug');
        $plan  = [];
        foreach ($cadences as $cadence) {
            $slug  = (string) $cadence['product_slug'];
            $quota = (int) $cadence['daily_quota'];
            $done  = $todayByProduct[$slug] ?? 0;

            $topics = [];
            foreach ($byProduct[$slug] ?? [] as $topic) {
                $key = (string) ($topic['key'] ?? '');
                if ($key === '' || strtolower((string) ($topic['status'] ?? 'planned')) === 'retired') {
                    continue;
                }
                if ($this->db->table('reach_content_items')->where('content_base_key', $key)->countAllResults() === 0) {
                    $topics[] = $topic;
                }
            }

            $plan[] = [
                'product_slug'    => $slug,
                'product_name'    => LaunchApps::nameFor($slug) ?? ucwords(str_replace('-', ' ', $slug)),
                'is_launch_app'   => in_array($slug, $launch, true),
                'repo'            => $repos[$slug] ?? null,
                'tier'            => $cadence['tier'],
                'daily_quota'     => $quota,
                'published_today' => $done,
                'remaining_today' => max(0, $quota - $done),
                'pending_topics'  => $topics,
            ];
        }

        $existing = $this->db->table('reach_content_items')
            ->select('title, slug, content_base_key')
            ->where('content_type', 'knowledge_base')
            ->where('deleted_at IS NULL', null, false)
            ->whereNotIn('workflow_status', ['rejected', 'archived'])
            ->orderBy('id', 'DESC')->limit(300)
            ->get()->getResultArray();

        return [
            'date'              => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'),
            'enabled'           => \App\Libraries\Automation\Routine\RoutineFlags::enabled('knowledge_base.enabled'),
            'quota'             => $this->submissions->quota('knowledge_base'),
            'launch_apps'       => LaunchApps::forRoutine(rtrim((string) env('AICOUNTLY_PUBLIC_SITE_BASE_URL', 'https://aicountly.com'), '/')),
            'products'          => $plan,
            'existing_articles' => $existing,
            'notes'             => (string) ($index['notes'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string, submission_id:int, content_item_id:int, content_version_id:int, slug:string, existing?:bool, quota?:array<string,int>}
     * @throws \InvalidArgumentException
     * @throws \App\Libraries\Automation\Routine\RoutineIntakeRefused switched off (403) or quota used (429)
     */
    public function ingestKbDraft(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 10) {
            throw new \InvalidArgumentException('title is required (10+ chars)');
        }

        $productSlug = trim((string) ($payload['product_slug'] ?? ''));
        if ($productSlug === '') {
            throw new \InvalidArgumentException('product_slug is required');
        }
        $cadence = $this->db->table('reach_kb_product_cadence')
            ->where('product_slug', $productSlug)
            ->where('enabled', true)
            ->get()->getRowArray();
        if (! $cadence) {
            throw new \InvalidArgumentException("product_slug '{$productSlug}' is not an enabled KB product");
        }

        $bodyHtml = (string) ($payload['body_html'] ?? '');
        if (trim($bodyHtml) === '') {
            throw new \InvalidArgumentException('body_html is required');
        }
        $bodyHtml = (new HtmlSanitizer())->purify($bodyHtml);
        $plain    = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_word_count($plain) < 150) {
            throw new \InvalidArgumentException('body too short: need at least 150 words');
        }

        $articleType = strtolower(trim((string) ($payload['article_type'] ?? 'concept')));
        $articleType = match ($articleType) {
            'getting_started', 'getting-started' => 'how_to',
            default => in_array($articleType, self::ARTICLE_TYPES, true) ? $articleType : 'concept',
        };

        $steps = KbSteps::normalize((array) ($payload['steps'] ?? []));
        if ($articleType === 'how_to' && $steps === []) {
            throw new \InvalidArgumentException('how_to articles require steps[] (each {title, text})');
        }

        $contentBaseKey = strtolower(trim((string) ($payload['content_base_key'] ?? $payload['content_key'] ?? '')));
        if ($contentBaseKey === '' || preg_match('/^[a-z0-9][a-z0-9._-]{2,119}$/', $contentBaseKey) !== 1) {
            throw new \InvalidArgumentException('content_base_key is required: 3-120 chars of a-z, 0-9, ".", "_" or "-" (e.g. kb-billing-create-invoice)');
        }
        $existing = $this->db->table('reach_content_items')
            ->select('id, slug, current_version_id')
            ->where('content_base_key', $contentBaseKey)
            ->get()->getRowArray();
        if ($existing) {
            return [
                'status'             => 'already_ingested',
                'submission_id'      => (int) ($this->submissions->findByItem((int) $existing['id'])['id'] ?? 0),
                'content_item_id'    => (int) $existing['id'],
                'content_version_id' => (int) ($existing['current_version_id'] ?? 0),
                'slug'               => (string) $existing['slug'],
                'existing'           => true,
            ];
        }

        $quota       = $this->submissions->assertAccepting('knowledge_base');
        $productId   = $this->ensureProduct($productSlug);
        $slug        = $this->uniqueSlug(trim((string) ($payload['seo']['slug'] ?? $payload['slug_hint'] ?? '')) ?: $title);
        $now         = date('Y-m-d H:i:s');
        $provenance  = (array) ($payload['provenance'] ?? []);

        $this->db->transStart();

        $this->db->table('reach_content_items')->insert([
            'uuid'               => $this->uuid(),
            'content_type'       => 'knowledge_base',
            'title'              => mb_substr($title, 0, 300),
            'slug'               => $slug,
            'content_base_key'   => $contentBaseKey,
            'workflow_status'    => 'draft',
            'approval_status'    => 'pending',
            'created_actor_type' => 'system',
            'created_by_service' => 'reach:automation_ingest_kb',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        $contentItemId = (int) $this->db->insertID();

        $this->db->table('reach_content_knowledge_base_details')->insert([
            'content_item_id'  => $contentItemId,
            'article_type'     => $articleType,
            'help_category'    => $payload['help_category'] ?? null,
            'difficulty_level' => $payload['difficulty_level'] ?? null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $version = (new ContentVersionService())->createVersion($contentItemId, [
            'title'           => $title,
            'summary'         => $payload['summary'] ?? null,
            'body_html'       => $bodyHtml,
            'body_markdown'   => $payload['body_markdown'] ?? null,
            'body_plain_text' => $plain,
        ], [
            'type'    => 'bot',
            'service' => 'reach:automation_ingest_kb:' . mb_substr((string) ($provenance['generated_by_model'] ?? 'claude'), 0, 40),
        ], 'Claude routine KB draft (awaiting Gemini vetting)');
        $versionId = (int) $version['id'];

        $this->db->table('reach_kb_publication_profiles')->insert([
            'content_item_id'          => $contentItemId,
            'article_type'             => $articleType,
            'product_id'               => $productId,
            'applicable_versions_json' => json_encode($payload['applicable_versions'] ?? ['type' => 'all'], JSON_UNESCAPED_SLASHES),
            'prerequisites_json'       => json_encode(array_values((array) ($payload['prerequisites'] ?? [])), JSON_UNESCAPED_SLASHES),
            'steps_json'               => json_encode($steps, JSON_UNESCAPED_SLASHES),
            'troubleshooting_json'     => json_encode(array_values((array) ($payload['troubleshooting'] ?? [])), JSON_UNESCAPED_SLASHES),
            'difficulty_level'         => in_array($payload['difficulty_level'] ?? '', ['beginner', 'intermediate', 'advanced', 'expert'], true)
                ? $payload['difficulty_level'] : null,
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        $seo = (array) ($payload['seo'] ?? []);
        $this->db->table('reach_content_seo_profiles')->insert([
            'content_item_id'      => $contentItemId,
            'content_version_id'   => $versionId,
            'primary_keyword'      => mb_substr((string) ($seo['primary_keyword'] ?? $title), 0, 255),
            'meta_title'           => mb_substr((string) ($seo['meta_title'] ?? $title), 0, 200),
            'meta_description'     => mb_substr((string) ($seo['meta_description'] ?? $payload['summary'] ?? $title), 0, 320),
            'slug'                 => $slug,
            'canonical_preference' => 'self_canonical',
            'seo_status'           => 'ready',
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        $submissionId = $this->submissions->create('knowledge_base', $contentItemId, $contentBaseKey, array_filter([
            'product_slug'   => $productSlug,
            'product_facts'  => array_values(array_filter(array_map('strval', (array) ($payload['product_facts'] ?? [])))),
            'sources'        => array_values(array_filter(array_map('strval', (array) ($payload['sources'] ?? [])))),
            'provenance'     => $provenance,
            'routine_run_id' => $provenance['routine_run_id'] ?? null,
            'verification_report' => is_array($payload['verification_report'] ?? null) ? $payload['verification_report'] : null,
        ], static fn ($v) => $v !== null && $v !== []));

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('kb ingest transaction failed');
        }

        \App\Libraries\AuditLogger::record('kb.ingested_pending_vetting', [
            'content_item_id' => $contentItemId,
            'submission_id'   => $submissionId,
            'product_slug'    => $productSlug,
            'article_type'    => $articleType,
            'generated_by'    => $provenance['generated_by_model'] ?? null,
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

    private function ensureProduct(string $slug): int
    {
        $row = $this->db->table('reach_products')
            ->where('slug', $slug)
            ->where('deleted_at IS NULL', null, false)
            ->get()->getRowArray();
        if ($row) {
            return (int) $row['id'];
        }

        $this->db->table('reach_products')->insert([
            'slug'               => $slug,
            'name'               => LaunchApps::nameFor($slug) ?? ucwords(str_replace('-', ' ', $slug)),
            'status'             => 'approved',
            'created_actor_type' => 'system',
            'created_by_service' => 'reach:automation_ingest_kb',
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    private function uniqueSlug(string $source): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $source) ?? '', '-'));
        $slug = mb_substr($slug !== '' ? $slug : 'kb-article', 0, 280);

        $candidate = $slug;
        $i         = 2;
        while ($this->db->table('reach_content_items')->where('slug', $candidate)->countAllResults() > 0) {
            $candidate = $slug . '-' . $i;
            $i++;
        }

        return $candidate;
    }

    private function uuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
