<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\ContentVersionService;
use App\Libraries\HtmlSanitizer;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Ingests fully-formed drafts submitted by the Claude Code routine.
 *
 * A draft is accepted only with a verification report authored by a DIFFERENT
 * model than the generator (two-model rule — mirrors the pipeline's
 * cross-provider guarantee). Accepted blog drafts enter the normal state
 * machine at FACT_VERIFIED and flow through GENERATE_IMAGE (gallery) →
 * SEO_OPTIMIZE → CROSS_REVIEW → publish exactly like pipeline items.
 */
class AutomationDraftIngestService
{
    private BaseConnection $db;
    private WorkBlockService $workBlocks;

    public function __construct(?BaseConnection $db = null, ?WorkBlockService $workBlocks = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->workBlocks = $workBlocks ?? new WorkBlockService();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:string, content_item_id:int, content_version_id:int, slug:string, existing?:bool}
     * @throws \InvalidArgumentException on validation failure (message is safe for the API response)
     */
    public function ingestBlogDraft(array $payload): array
    {
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
        if ($words < 200) {
            throw new \InvalidArgumentException("body too short: {$words} words, need at least 200");
        }

        $this->assertVerificationReport($payload);

        $contentBaseKey = trim((string) ($payload['content_base_key'] ?? ''));
        $slugHint       = trim((string) ($payload['seo']['slug'] ?? $payload['slug_hint'] ?? ''));

        // Idempotency: same content_base_key (or slug) returns the existing item.
        if ($contentBaseKey !== '') {
            $existing = $this->db->table('reach_content_items i')
                ->join('reach_topic_candidates c', 'c.content_item_id = i.id')
                ->select('i.id, i.slug, i.current_version_id')
                ->where('c.content_base_key', $contentBaseKey)
                ->get()->getRowArray();
            if ($existing) {
                return [
                    'status'             => 'already_ingested',
                    'content_item_id'    => (int) $existing['id'],
                    'content_version_id' => (int) ($existing['current_version_id'] ?? 0),
                    'slug'               => (string) $existing['slug'],
                    'existing'           => true,
                ];
            }
        }

        $slug     = $this->workBlocks->buildUniqueSlug($slugHint !== '' ? $slugHint : $title);
        $now      = date('Y-m-d H:i:s');
        $provenance = (array) ($payload['provenance'] ?? []);

        $this->db->transStart();

        $this->db->table('reach_content_items')->insert([
            'uuid'               => $this->uuid(),
            'content_type'       => 'blog',
            'title'              => mb_substr($title, 0, 300),
            'slug'               => $slug,
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
            'last_verification_json' => json_encode($payload['verification_report'], JSON_UNESCAPED_SLASHES),
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
            'service' => 'reach:automation_ingest:' . (string) ($provenance['generated_by_model'] ?? 'claude'),
        ], 'Claude routine draft (pre-verified by ' . (string) ($payload['verification_report']['model'] ?? 'reviewer') . ')');
        $versionId = (int) $version['id'];

        // SEO + publication profiles (category/tags land on the profile).
        $this->workBlocks->ensurePublicationProfilesForItem($contentItemId);
        $this->applySeoAndProfile($contentItemId, $payload);

        // Link back to the content-base candidate when one exists.
        if ($contentBaseKey !== '') {
            $this->db->table('reach_topic_candidates')
                ->where('content_base_key', $contentBaseKey)
                ->update([
                    'content_item_id' => $contentItemId,
                    'status'          => 'roadmap_selected',
                    'updated_at'      => $now,
                ]);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('ingest transaction failed');
        }

        \App\Libraries\AuditLogger::record('blog.automation_ingest', [
            'content_item_id' => $contentItemId,
            'origin'          => 'claude_routine',
            'generated_by'    => $provenance['generated_by_model'] ?? null,
            'reviewed_by'     => $payload['verification_report']['model'] ?? null,
            'routine_run_id'  => $provenance['routine_run_id'] ?? null,
            'words'           => $words,
        ]);

        // Enter the normal chain at fact_verified (draft -> fact_verified is a
        // legal adjacency): GENERATE_IMAGE (gallery) → SEO → CROSS_REVIEW → …
        (new BlogStateMachine($this->workBlocks))->transition($contentItemId, BlogStateMachine::FACT_VERIFIED, null, [
            'content_version_id' => $versionId,
            'reason'             => 'claude_routine_ingest_preverified',
        ]);

        return [
            'status'             => 'ingested',
            'content_item_id'    => $contentItemId,
            'content_version_id' => $versionId,
            'slug'               => $slug,
        ];
    }

    /**
     * Two-model rule: a report is mandatory and its author must differ from
     * the generator. Without it the fail-closed verification guarantee would
     * silently erode for routine content.
     *
     * @param array<string,mixed> $payload
     */
    private function assertVerificationReport(array $payload): void
    {
        $report = $payload['verification_report'] ?? null;
        if (! is_array($report) || $report === []) {
            throw new \InvalidArgumentException('verification_report is required');
        }

        $reviewer  = strtolower(trim((string) ($report['model'] ?? '')));
        $generator = strtolower(trim((string) ($payload['provenance']['generated_by_model'] ?? '')));

        if ($reviewer === '') {
            throw new \InvalidArgumentException('verification_report.model is required');
        }
        if ($generator === '') {
            throw new \InvalidArgumentException('provenance.generated_by_model is required');
        }
        if ($reviewer === $generator) {
            throw new \InvalidArgumentException('verification_report.model must differ from provenance.generated_by_model (two-model rule)');
        }

        $passRate = $report['pass_rate'] ?? null;
        if (! is_numeric($passRate)) {
            throw new \InvalidArgumentException('verification_report.pass_rate is required (0..1)');
        }
        $threshold = (float) env('BLOG_VERIFICATION_PASS_THRESHOLD', 0.95);
        if ((float) $passRate < $threshold) {
            throw new \InvalidArgumentException(
                "verification_report.pass_rate {$passRate} below threshold {$threshold}; revise before submitting"
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function applySeoAndProfile(int $contentItemId, array $payload): void
    {
        $now = date('Y-m-d H:i:s');
        $seo = (array) ($payload['seo'] ?? []);

        if (! empty($seo['meta_title']) || ! empty($seo['meta_description'])) {
            $update = array_filter([
                'meta_title'       => isset($seo['meta_title']) ? mb_substr((string) $seo['meta_title'], 0, 120) : null,
                'meta_description' => isset($seo['meta_description']) ? mb_substr((string) $seo['meta_description'], 0, 320) : null,
            ]);
            if ($update !== []) {
                $this->db->table('reach_content_seo_profiles')
                    ->where('content_item_id', $contentItemId)
                    ->update($update + ['updated_at' => $now]);
            }
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
