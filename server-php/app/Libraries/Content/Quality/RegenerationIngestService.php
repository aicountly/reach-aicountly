<?php

declare(strict_types=1);

namespace App\Libraries\Content\Quality;

use App\Libraries\AuditLogger;
use App\Libraries\Automation\Routine\KbSteps;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\BlogTaxonomy;
use App\Libraries\Database\SchemaGuard;
use App\Libraries\ContentVersionService;
use App\Libraries\HtmlSanitizer;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Accepts a routine's rewrite of a queued item (`regeneration_id` on a
 * blog-drafts / kb-drafts submission) onto the SAME content item:
 *
 *   - a new content version, noted as the replacement of the failed one
 *     (the version history keeps both);
 *   - the slug never changes, so the article is republished at its URL;
 *   - the blog cover is cleared so the pipeline's cover stage sources a
 *     fresh one through the normal cover hook (RoutineCoverService);
 *   - the item goes back to draft / pending approval and its routine
 *     submission restarts at pending_vetting (origin `regeneration`, counted
 *     against today's quota).
 *
 * Nothing here approves or publishes: RoutineContentPipeline vets it.
 */
class RegenerationIngestService
{
    private BaseConnection $db;
    private RoutineSubmissions $submissions;
    private RegenerationQueue $queue;

    public function __construct(?BaseConnection $db = null, ?RoutineSubmissions $submissions = null)
    {
        $this->db          = $db ?? Database::connect();
        $this->submissions = $submissions ?? new RoutineSubmissions($this->db);
        $this->queue       = new RegenerationQueue($this->db);
    }

    /**
     * @param array<string, mixed> $payload the routine's normal submission plus regeneration_id
     * @return array<string, mixed> same shape as a normal ingest result
     * @throws \InvalidArgumentException
     * @throws \App\Libraries\Automation\Routine\RoutineIntakeRefused
     */
    public function ingest(string $contentType, array $payload): array
    {
        $regenId = (int) ($payload['regeneration_id'] ?? 0);
        $regen   = $regenId > 0 ? $this->queue->find($regenId) : null;
        if ($regen === null || $regen['content_type'] !== $contentType) {
            throw new \InvalidArgumentException("regeneration_id {$regenId} is not a queued {$contentType} regeneration");
        }
        $itemId = (int) $regen['content_item_id'];
        if ($regen['status'] === 'submitted' || $regen['status'] === 'live') {
            $sub = $this->submissions->findByItem($itemId);

            return [
                'status' => 'already_ingested', 'submission_id' => (int) ($sub['id'] ?? 0), 'content_item_id' => $itemId,
                'content_version_id' => (int) ($regen['new_version_id'] ?? 0), 'slug' => (string) $regen['slug'],
                'existing' => true, 'regeneration_id' => $regenId,
            ];
        }
        if (! in_array($regen['status'], ['queued', 'claimed'], true)) {
            throw new \InvalidArgumentException("regeneration {$regenId} is {$regen['status']}; fetch GET v1/automation/regenerate/next for open work");
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if (mb_strlen($title) < 10) {
            throw new \InvalidArgumentException('title is required (10+ chars)');
        }
        $body  = (new HtmlSanitizer())->purify((string) ($payload['body_html'] ?? ''));
        $plain = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $min   = $contentType === 'blog' ? 300 : 150;
        if (str_word_count($plain) < $min) {
            throw new \InvalidArgumentException("body too short: need at least {$min} words");
        }

        $quota    = $this->submissions->assertAccepting($contentType);
        $item     = $this->db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray();
        $previous = (int) ($item['current_version_id'] ?? 0);
        $prevNo   = (int) ($this->db->table('reach_content_versions')->select('version_number')->where('id', $previous)->get()->getRowArray()['version_number'] ?? 0);
        $reasons  = (array) json_decode((string) $regen['reasons'], true);
        $now      = date('Y-m-d H:i:s');

        $this->db->transStart();

        $version = (new ContentVersionService())->createVersion($itemId, [
            'title'           => $title,
            'summary'         => $payload['summary'] ?? null,
            'body_html'       => $body,
            'body_markdown'   => null,
            'body_plain_text' => $plain,
        ], ['type' => 'bot', 'service' => 'reach:regeneration:' . mb_substr((string) (((array) ($payload['provenance'] ?? []))['generated_by_model'] ?? 'claude'), 0, 40)],
            mb_substr("Regenerated replacement for v{$prevNo} (regeneration #{$regenId}); the earlier text failed vetting: " . implode(' ', array_slice($reasons, 0, 3)), 0, 900));
        $versionId = (int) $version['id'];

        $this->db->table('reach_content_items')->where('id', $itemId)->update([
            'title' => mb_substr($title, 0, 300), 'approval_status' => 'pending', 'risk_level' => 'low', 'updated_at' => $now,
        ]);
        $this->resetWorkflow($contentType, $itemId);
        $this->applySeo($itemId, (array) ($payload['seo'] ?? []), $now);

        $taxonomy = null;
        if ($contentType === 'blog') {
            // A rewrite keeps the post where it is filed: the stored category
            // (canonicalised), else the routine's if it is a valid one; never a
            // 422 here — a rewrite of a live post must not fail on taxonomy.
            $taxonomy = $this->blogTaxonomy($itemId, $payload);
            // A fresh cover for the rewrite: the pipeline's cover stage asks the
            // cover hook for one whenever the profile has none.
            $profile = ['featured_image_reference' => null, 'featured_image_alt' => null, 'updated_at' => $now];
            if ($taxonomy['category'] !== null) {
                $profile['category'] = $taxonomy['category'];
            }
            if (SchemaGuard::hasColumn($this->db, 'reach_blog_publication_profiles', 'product_slug')) {
                $profile['product_slug'] = $taxonomy['product_slug'];
            }
            $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->update($profile);
        } else {
            $kb = [];
            foreach (['prerequisites', 'troubleshooting'] as $f) {
                if (is_array($payload[$f] ?? null)) {
                    $kb[$f . '_json'] = json_encode(array_values($payload[$f]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            if (is_array($payload['steps'] ?? null) && $payload['steps'] !== []) {
                $kb['steps_json'] = json_encode(KbSteps::normalize($payload['steps']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($kb !== []) {
                $this->db->table('reach_kb_publication_profiles')->where('content_item_id', $itemId)->update($kb + ['updated_at' => $now]);
            }
        }

        $submissionJson = array_filter([
            'category'        => $taxonomy['category'] ?? null,
            'product_slug'    => $taxonomy !== null ? $taxonomy['product_slug'] : ($payload['product_slug'] ?? $regen['product_slug']),
            'product_facts'   => array_values(array_filter(array_map('strval', (array) ($payload['product_facts'] ?? [])))),
            'sources'         => array_values(array_filter(array_map('strval', (array) ($payload['sources'] ?? [])))),
            'cover_prompt'    => isset($payload['cover_prompt']) ? mb_substr((string) $payload['cover_prompt'], 0, 300) : null,
            'provenance'      => (array) ($payload['provenance'] ?? []),
            'regeneration_id' => $regenId,
            'replaces_version_id' => $previous ?: null,
        ], static fn ($v) => $v !== null && $v !== []);

        $existing = $this->submissions->findByItem($itemId);
        if ($existing !== null) {
            $submissionId = (int) $existing['id'];
            $this->db->table('reach_routine_submissions')->where('id', $submissionId)->update([
                'status' => RoutineSubmissions::PENDING_VETTING, 'origin' => 'regeneration', 'decision' => null,
                'reasons_json' => '[]', 'scores_json' => '{}', 'vetting_model' => null, 'vetting_run_id' => null,
                'vetting_run_ids_json' => '[]', 'vetting_attempts' => 0, 'next_attempt_at' => null, 'risk_flag' => null,
                'approved_by' => null, 'approved_at' => null, 'approval_id' => null, 'final_version_id' => null,
                'final_checksum' => null, 'cover_status' => null, 'cover_url' => null, 'cover_alt' => null,
                'cover_attempts' => 0, 'next_cover_attempt_at' => null, 'deployment_id' => null, 'publish_attempts' => 0,
                'published_at' => null, 'verified_at' => null, 'locked_until' => null, 'last_error' => null,
                'submission_json' => json_encode($submissionJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                // The quota counts submissions created today; a rewrite is today's work.
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $submissionId = $this->submissions->create($contentType, $itemId, $item['content_base_key'] ?? null, $submissionJson);
            $this->db->table('reach_routine_submissions')->where('id', $submissionId)->update(['origin' => 'regeneration']);
        }

        $this->queue->markSubmitted($regenId, $submissionId, $versionId);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('regeneration ingest transaction failed');
        }

        AuditLogger::record('content.regeneration_submitted', [
            'content_item_id' => $itemId, 'regeneration_id' => $regenId, 'submission_id' => $submissionId,
            'replaced_version_id' => $previous, 'new_version_id' => $versionId,
        ]);

        return [
            'status'             => 'ingested',
            'submission_id'      => $submissionId,
            'content_item_id'    => $itemId,
            'content_version_id' => $versionId,
            'slug'               => (string) $item['slug'],
            'regeneration_id'    => $regenId,
            'quota'              => ['daily_limit' => $quota['daily_limit'], 'used_today' => $quota['used_today'] + 1, 'remaining_today' => max(0, $quota['remaining_today'] - 1)],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{category:?string, product_slug:?string}
     */
    private function blogTaxonomy(int $itemId, array $payload): array
    {
        $hasProduct = SchemaGuard::hasColumn($this->db, 'reach_blog_publication_profiles', 'product_slug');
        $stored     = $this->db->table('reach_blog_publication_profiles')
            ->select($hasProduct ? 'category, product_slug' : 'category')
            ->where('content_item_id', $itemId)->get()->getRowArray() ?? [];

        return [
            'category'     => BlogTaxonomy::canonical($stored['category'] ?? null) ?? BlogTaxonomy::canonical((string) ($payload['category'] ?? '')),
            'product_slug' => BlogTaxonomy::canonicalProduct($stored['product_slug'] ?? null) ?? BlogTaxonomy::canonicalProduct((string) ($payload['product_slug'] ?? '')),
        ];
    }

    /** Back to draft so the pipeline walks review → approved → publish again. */
    private function resetWorkflow(string $contentType, int $itemId): void
    {
        $now = date('Y-m-d H:i:s');
        if ($contentType !== 'blog') {
            $this->db->table('reach_content_items')->where('id', $itemId)->update(['workflow_status' => 'draft', 'updated_at' => $now]);

            return;
        }
        $current = strtolower((string) ($this->db->table('reach_content_items')->select('workflow_status')->where('id', $itemId)->get()->getRowArray()['workflow_status'] ?? ''));
        $sm = new BlogStateMachine();
        if ($current !== BlogStateMachine::DRAFT && $sm->canTransition($current, BlogStateMachine::DRAFT)) {
            try {
                $sm->transition($itemId, BlogStateMachine::DRAFT, null, ['reason' => 'regeneration_submitted', 'skip_successor_work_block' => true]);

                return;
            } catch (\Throwable) {
            }
        }
        // Any other state (approved, failed, publish limbo): set it directly —
        // the routine submission, not the workflow column, drives the pipeline.
        $this->db->table('reach_content_items')->where('id', $itemId)->update(['workflow_status' => BlogStateMachine::DRAFT, 'updated_at' => $now]);
        $this->db->table('reach_content_blog_details')->where('content_item_id', $itemId)->update(['automation_state' => BlogStateMachine::DRAFT, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $seo */
    private function applySeo(int $itemId, array $seo, string $now): void
    {
        $update = array_filter([
            'meta_title'       => isset($seo['meta_title']) ? mb_substr((string) $seo['meta_title'], 0, 120) : null,
            'meta_description' => isset($seo['meta_description']) ? mb_substr((string) $seo['meta_description'], 0, 320) : null,
            'primary_keyword'  => isset($seo['primary_keyword']) ? mb_substr((string) $seo['primary_keyword'], 0, 255) : null,
        ]);
        if ($update !== []) {
            $this->db->table('reach_content_seo_profiles')->where('content_item_id', $itemId)->update($update + ['updated_at' => $now]);
        }
    }
}
