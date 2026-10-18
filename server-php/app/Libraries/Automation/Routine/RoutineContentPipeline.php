<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\AuditLogger;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Blog\BlogFeatureFlags;
use App\Libraries\Blog\BlogStateMachine;
use App\Libraries\Blog\WorkBlockService;
use App\Libraries\ContentValidationService;
use App\Libraries\ContentVersionService;
use App\Libraries\HtmlSanitizer;
use App\Libraries\Publishing\Blog\BlogReadinessService;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Publishing\PublicationConnectionService;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Claude-routine blog and knowledge-base pipeline:
 *
 *   ingest → vet (Gemini; revise is re-vetted once) → approve under the
 *   SystemApprover with a checksum of the FINAL text → SEO + publication
 *   profile (author) → cover (blog: relevant gallery cover, else fresh GPT Image) → publish
 *   through PublicationDeploymentService → verify the public copy.
 *
 * The vetting run is the verification and the review of record: for routine
 * items this replaces CROSS_REVIEW and HUMAN_REVIEW_GATE. Nothing here
 * publishes unvetted content — a vetting outage leaves the draft pending and
 * the sweep retries with backoff. Each pass is resumable: state lives in
 * reach_routine_submissions, so a pass that dies is picked up by the next.
 */
class RoutineContentPipeline
{
    private const VERIFY_SOURCE = 'reach:routine_pipeline';
    private const MAX_PUBLISH_ATTEMPTS = 3;

    private BaseConnection $db;
    private RoutineSubmissions $submissions;
    private ?ContentVettingService $vetting;
    private ?RoutineCoverService $covers;

    public function __construct(
        ?ContentVettingService $vetting = null,
        ?RoutineCoverService $covers = null,
        ?RoutineSubmissions $submissions = null,
    ) {
        $this->db          = Database::connect();
        $this->vetting     = $vetting;
        $this->covers      = $covers;
        $this->submissions = $submissions ?? new RoutineSubmissions($this->db);
    }

    /**
     * Run every stage that is due for one submission.
     *
     * @return array<string, mixed> the submission row after the pass
     */
    public function process(int $submissionId): array
    {
        $row = $this->submissions->find($submissionId);
        if ($row === null) {
            throw new \RuntimeException("Routine submission {$submissionId} not found");
        }
        if (! $this->submissions->claim($submissionId)) {
            return $row + ['busy' => true];
        }

        try {
            // Each stage either advances the status (loop again) or leaves it
            // where it is (stop): a vetting outage, a missing cover, a queued
            // deployment the worker has not sent yet.
            for ($i = 0; $i < 6; $i++) {
                $before = $row['status'];
                match ($before) {
                    RoutineSubmissions::PENDING_VETTING => $this->vetStage($row),
                    RoutineSubmissions::AWAITING_COVER  => $this->coverStage($row),
                    RoutineSubmissions::APPROVED, RoutineSubmissions::PUBLISH_BLOCKED => $this->publishStage($row),
                    RoutineSubmissions::PUBLISHING      => $this->verifyStage($row),
                    default => null,
                };
                $row = $this->submissions->find($submissionId) ?? $row;
                if ($row['status'] === $before) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->submissions->update($submissionId, ['last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            log_message('error', "[RoutinePipeline] submission {$submissionId}: " . $e->getMessage());
        } finally {
            $this->submissions->release($submissionId);
        }

        return $this->submissions->find($submissionId) ?? $row;
    }

    /**
     * Operator "retry": clear the backoff and run a pass now.
     *
     * @return array<string, mixed>
     */
    public function retry(int $submissionId): array
    {
        $row = $this->submissions->find($submissionId);
        if ($row === null) {
            throw new \RuntimeException("Routine submission {$submissionId} not found");
        }
        if ($row['status'] === RoutineSubmissions::PUBLISH_FAILED) {
            $this->submissions->update($submissionId, ['status' => RoutineSubmissions::PUBLISH_BLOCKED, 'publish_attempts' => 0]);
        }
        $this->submissions->update($submissionId, ['next_attempt_at' => null, 'next_cover_attempt_at' => null]);

        return $this->process($submissionId);
    }

    /**
     * Scheduled pass over every submission with work due: pending vetting
     * (with backoff), covers (hourly), publishes and verification.
     *
     * @return array{processed: int, by_status: array<string, int>}
     */
    public function sweep(int $limit = 25): array
    {
        $byStatus = [];
        $count    = 0;
        foreach ($this->submissions->dueIds($limit) as $id) {
            $row = $this->process($id);
            $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
            $count++;
        }

        return ['processed' => $count, 'by_status' => $byStatus];
    }

    // ── Stage 1: vetting ────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function vetStage(array $row): void
    {
        $id     = (int) $row['id'];
        $type   = (string) $row['content_type'];
        $itemId = (int) $row['content_item_id'];
        $item   = $this->item($itemId);
        $draft  = $type === 'blog' ? $this->blogDraft($item) : $this->kbDraft($item);

        try {
            $verdict = (new RoutineVetting($this->vetting ?? new ContentVettingService()))
                ->run($type, $draft, $this->vettingContext($row), "{$type}:{$itemId}");
        } catch (VettingUnavailableException $e) {
            $attempts = (int) $row['vetting_attempts'] + 1;
            // 5, 10, 20, 40 … minutes, capped at 6 hours: a Gemini outage is
            // retried all day, never by publishing unvetted content.
            $delay = min(360, 5 * (2 ** min(6, $attempts - 1)));
            $this->submissions->update($id, [
                'vetting_attempts' => $attempts,
                'next_attempt_at'  => date('Y-m-d H:i:s', time() + $delay * 60),
                'last_error'       => 'Vetting unavailable: ' . mb_substr($e->getMessage(), 0, 500),
            ]);

            return;
        }

        $record = [
            'decision'             => $verdict['decision'],
            'reasons_json'         => $verdict['reasons'],
            'scores_json'          => $verdict['scores'],
            'vetting_model'        => $verdict['model'],
            'vetting_run_id'       => $verdict['run_id'],
            'vetting_run_ids_json' => $verdict['run_ids'],
            'vetting_attempts'     => (int) $row['vetting_attempts'] + 1,
            'next_attempt_at'      => null,
            'last_error'           => null,
        ];

        if (! $verdict['approved']) {
            $this->submissions->update($id, $record + ['status' => RoutineSubmissions::REJECTED]);
            $this->retireItem($type, $itemId, $verdict);

            return;
        }

        $versionId = $type === 'blog'
            ? $this->applyBlogFinal($itemId, $item, $verdict['final'])
            : $this->applyKbFinal($itemId, $item, $verdict['final']);
        $record['final_version_id'] = $versionId;

        if ($verdict['high_risk'] !== null) {
            $this->submissions->update($id, $record + ['status' => RoutineSubmissions::HELD_HIGH_RISK, 'risk_flag' => $verdict['high_risk']]);
            $this->holdHighRisk($type, $itemId, $verdict);

            return;
        }

        $approval = $type === 'blog'
            ? $this->approveBlog($itemId, $versionId, $verdict)
            : $this->approveKb($itemId, $versionId, $verdict);

        $this->submissions->update($id, $record + [
            'status'         => $type === 'blog' ? RoutineSubmissions::AWAITING_COVER : RoutineSubmissions::APPROVED,
            'approved_by'    => $approval['approved_by'],
            'approved_at'    => date('Y-m-d H:i:s'),
            'approval_id'    => $approval['approval_id'],
            'final_checksum' => $approval['checksum'],
        ]);
    }

    /** @param array<string, mixed> $item */
    private function blogDraft(array $item): array
    {
        $version = $this->currentVersion((int) $item['id']);
        $seo     = $this->seo((int) $item['id']);

        return [
            'title'            => (string) ($version['title'] ?? $item['title']),
            'summary'          => (string) ($version['summary'] ?? ''),
            'body_html'        => (string) ($version['body_html'] ?? ''),
            'meta_title'       => (string) ($seo['meta_title'] ?? ''),
            'meta_description' => (string) ($seo['meta_description'] ?? ''),
            'slug'             => (string) ($seo['slug'] ?? $item['slug']),
        ];
    }

    /** @param array<string, mixed> $item */
    private function kbDraft(array $item): array
    {
        $version = $this->currentVersion((int) $item['id']);
        $seo     = $this->seo((int) $item['id']);
        $profile = $this->db->table('reach_kb_publication_profiles')->where('content_item_id', $item['id'])->get()->getRowArray() ?? [];
        $decode  = static fn ($v): array => is_string($v) ? (json_decode($v, true) ?: []) : (array) ($v ?? []);

        return [
            'title'            => (string) ($version['title'] ?? $item['title']),
            'summary'          => (string) ($version['summary'] ?? ''),
            'body_html'        => (string) ($version['body_html'] ?? ''),
            'prerequisites'    => $decode($profile['prerequisites_json'] ?? null),
            'steps'            => $decode($profile['steps_json'] ?? null),
            'troubleshooting'  => $decode($profile['troubleshooting_json'] ?? null),
            'meta_title'       => (string) ($seo['meta_title'] ?? ''),
            'meta_description' => (string) ($seo['meta_description'] ?? ''),
        ];
    }

    /**
     * What the vetting model may rely on: the date, the products as Reach
     * records them, and the product facts and sources the routine read from
     * the product repositories while writing.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function vettingContext(array $row): array
    {
        $sub = (array) ($row['submission_json'] ?? []);

        $products = [];
        try {
            foreach ($this->db->table('reach_products')
                ->select('name, slug, short_description')
                ->where('deleted_at IS NULL', null, false)
                ->whereIn('slug', LaunchApps::slugs())
                ->get()->getResultArray() as $p) {
                $products[] = array_filter($p, static fn ($v) => $v !== null && $v !== '');
            }
        } catch (\Throwable) {
        }

        return array_filter([
            'content_type'  => $row['content_type'] === 'blog' ? 'Marketing/educational blog for aicountly.com/blogs' : 'Help-centre article for aicountly.com/help',
            'launch_apps'   => array_column(LaunchApps::APPS, 'name'),
            'products'      => $products,
            'product_facts' => array_values(array_filter(array_map('strval', (array) ($sub['product_facts'] ?? [])))),
            'sources'       => array_values(array_filter(array_map('strval', (array) ($sub['sources'] ?? [])))),
            'product_slug'  => $sub['product_slug'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Write the approved text as the item's current version (a new version
     * only when vetting changed something) and carry the SEO fields over.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $final
     */
    private function applyBlogFinal(int $itemId, array $item, array $final): int
    {
        $version = $this->currentVersion($itemId);
        $versionId = (int) ($version['id'] ?? 0);

        $title = trim((string) ($final['title'] ?? '')) ?: (string) $version['title'];
        $body  = (new HtmlSanitizer())->purify((string) ($final['body_html'] ?? $version['body_html']));
        $summary = (string) ($final['summary'] ?? $version['summary'] ?? '');

        if ($title !== (string) $version['title'] || $body !== (string) $version['body_html'] || $summary !== (string) ($version['summary'] ?? '')) {
            $new = (new ContentVersionService())->createVersion($itemId, [
                'title'           => $title,
                'summary'         => $summary,
                'body_html'       => $body,
                // The markdown no longer matches a body the vetter corrected.
                'body_markdown'   => $body === (string) $version['body_html'] ? ($version['body_markdown'] ?? null) : null,
                'body_plain_text' => trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ], ['type' => 'bot', 'service' => self::VERIFY_SOURCE], 'Vetted text (Gemini corrections applied)');
            $versionId = (int) $new['id'];
            $this->db->table('reach_content_items')->where('id', $itemId)->update(['title' => mb_substr($title, 0, 300), 'updated_at' => date('Y-m-d H:i:s')]);
        }

        $this->applySeo($itemId, $item, $final);

        return $versionId;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $final
     */
    private function applyKbFinal(int $itemId, array $item, array $final): int
    {
        $versionId = $this->applyBlogFinal($itemId, $item, array_intersect_key($final, array_flip(['title', 'summary', 'body_html', 'meta_title', 'meta_description'])));

        $update = [];
        foreach (['prerequisites', 'troubleshooting'] as $field) {
            if (isset($final[$field]) && is_array($final[$field])) {
                $update[$field . '_json'] = json_encode(array_values($final[$field]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if (isset($final['steps']) && is_array($final['steps']) && $final['steps'] !== []) {
            $update['steps_json'] = json_encode(KbSteps::normalize($final['steps']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($update !== []) {
            $this->db->table('reach_kb_publication_profiles')->where('content_item_id', $itemId)->update($update + ['updated_at' => date('Y-m-d H:i:s')]);
        }

        return $versionId;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $final
     */
    private function applySeo(int $itemId, array $item, array $final): void
    {
        $patch = array_filter([
            'meta_title'       => trim((string) ($final['meta_title'] ?? '')) !== '' ? mb_substr(trim((string) $final['meta_title']), 0, 120) : null,
            'meta_description' => trim((string) ($final['meta_description'] ?? '')) !== '' ? mb_substr(trim((string) $final['meta_description']), 0, 320) : null,
        ]);

        // A corrected slug is taken only when it is well-formed and free —
        // never at the cost of a collision the readiness gate would block.
        $slug = strtolower(trim((string) ($final['slug'] ?? '')));
        if ($slug !== '' && $slug !== (string) $item['slug'] && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1
            && $this->db->table('reach_content_items')->where('slug', $slug)->where('id !=', $itemId)->countAllResults() === 0
            && $this->db->table('reach_content_seo_profiles')->where('slug', $slug)->where('content_item_id !=', $itemId)->countAllResults() === 0
        ) {
            $patch['slug'] = $slug;
            $this->db->table('reach_content_items')->where('id', $itemId)->update(['slug' => $slug, 'updated_at' => date('Y-m-d H:i:s')]);
        }

        if ($patch !== []) {
            $this->db->table('reach_content_seo_profiles')->where('content_item_id', $itemId)->update($patch + ['updated_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * @param array<string, mixed> $verdict
     * @return array{approval_id: ?int, checksum: string, approved_by: int}
     */
    private function approveBlog(int $itemId, int $versionId, array $verdict): array
    {
        $actorId   = SystemApprover::actorId();
        $approvals = new BlogContentApprovalService();
        $approval  = $approvals->approve(
            $itemId,
            $versionId,
            $actorId,
            'standard',
            sprintf('Approved by the vetting model %s (run #%s, %s). Recorded under the Reach System Bot; no person reviewed it.',
                $verdict['model'], $verdict['run_id'] ?? 'n/a', $verdict['decision']),
        );
        $this->db->table('reach_blog_content_approvals')->where('id', $approval['approval_id'])->update(['vetting_run_id' => $verdict['run_id']]);

        $this->db->table('reach_content_blog_details')->where('content_item_id', $itemId)->update([
            'risk_class'             => 'LOW',
            'last_verification_json' => json_encode($this->verificationRecord($itemId, $verdict), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        $sm   = new BlogStateMachine();
        $meta = ['content_version_id' => $versionId, 'reason' => 'routine_vetting_approved', 'vetting_run_id' => $verdict['run_id'], 'approved_by_system_user' => $actorId];
        $this->walk($sm, $itemId, [BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::APPROVED], $meta);

        AuditLogger::record('blog.routine_vetted_approved', [
            'content_item_id' => $itemId, 'content_version_id' => $versionId,
            'vetting_run_id'  => $verdict['run_id'], 'model' => $verdict['model'], 'decision' => $verdict['decision'],
        ], $actorId);

        return ['approval_id' => (int) $approval['approval_id'], 'checksum' => (string) $approval['checksum'], 'approved_by' => $actorId];
    }

    /**
     * @param array<string, mixed> $verdict
     * @return array{approval_id: ?int, checksum: string, approved_by: int}
     */
    private function approveKb(int $itemId, int $versionId, array $verdict): array
    {
        $actorId  = SystemApprover::actorId();
        $checksum = (new BlogContentApprovalService())->checksumForVersion($versionId);
        $now      = date('Y-m-d H:i:s');

        $this->db->table('reach_content_items')->where('id', $itemId)->update([
            'workflow_status' => 'approved',
            'approval_status' => 'approved',
            'approved_by'     => $actorId,
            'approved_at'     => $now,
            'updated_at'      => $now,
        ]);

        AuditLogger::record('kb.routine_vetted_approved', [
            'content_item_id' => $itemId, 'content_version_id' => $versionId, 'checksum' => $checksum,
            'vetting_run_id'  => $verdict['run_id'], 'model' => $verdict['model'], 'decision' => $verdict['decision'],
        ], $actorId);

        return ['approval_id' => null, 'checksum' => $checksum, 'approved_by' => $actorId];
    }

    /**
     * @param array<string, mixed> $verdict
     * @return array<string, mixed>
     */
    private function verificationRecord(int $itemId, array $verdict): array
    {
        $existing = json_decode((string) ($this->db->table('reach_content_blog_details')->select('last_verification_json')
            ->where('content_item_id', $itemId)->get()->getRowArray()['last_verification_json'] ?? ''), true);

        return [
            'verified_by'    => 'vetting_model',
            'model'          => $verdict['model'],
            'vetting_run_id' => $verdict['run_id'],
            'decision'       => $verdict['decision'],
            'scores'         => $verdict['scores'],
            'reasons'        => $verdict['reasons'],
            // Optional self-check the routine may still send; informational only.
            'routine_report' => is_array($existing) ? ($existing['routine_report'] ?? $existing) : null,
        ];
    }

    /** @param array<string, mixed> $verdict */
    private function retireItem(string $type, int $itemId, array $verdict): void
    {
        $now = date('Y-m-d H:i:s');
        if ($type === 'blog') {
            $this->walk(new BlogStateMachine(), $itemId, [BlogStateMachine::INTERNAL_REVIEW, BlogStateMachine::REJECTED], [
                'reason' => 'routine_vetting_' . $verdict['decision'], 'vetting_run_id' => $verdict['run_id'],
            ]);
            $this->db->table('reach_content_items')->where('id', $itemId)->update(['approval_status' => 'rejected', 'updated_at' => $now]);
        } else {
            $this->db->table('reach_content_items')->where('id', $itemId)->update(['workflow_status' => 'rejected', 'approval_status' => 'rejected', 'updated_at' => $now]);
        }

        AuditLogger::record($type === 'blog' ? 'blog.routine_vetting_rejected' : 'kb.routine_vetting_rejected', [
            'content_item_id' => $itemId, 'decision' => $verdict['decision'],
            'vetting_run_id'  => $verdict['run_id'], 'reasons' => array_slice($verdict['reasons'], 0, 10),
        ]);
    }

    /** @param array<string, mixed> $verdict */
    private function holdHighRisk(string $type, int $itemId, array $verdict): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_content_items')->where('id', $itemId)->update(['risk_level' => 'high', 'approval_status' => 'pending', 'updated_at' => $now]);
        if ($type === 'blog') {
            $this->db->table('reach_content_blog_details')->where('content_item_id', $itemId)->update([
                'risk_class'             => 'HIGH',
                'last_verification_json' => json_encode($this->verificationRecord($itemId, $verdict), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at'             => $now,
            ]);
            // internal_review is the Command Centre's human queue.
            $this->walk(new BlogStateMachine(), $itemId, [BlogStateMachine::INTERNAL_REVIEW], ['reason' => 'routine_vetting_high_risk', 'vetting_run_id' => $verdict['run_id']]);
        } else {
            $this->db->table('reach_content_items')->where('id', $itemId)->update(['workflow_status' => 'review_pending']);
        }

        AuditLogger::record($type === 'blog' ? 'blog.routine_held_high_risk' : 'kb.routine_held_high_risk', [
            'content_item_id' => $itemId, 'vetting_run_id' => $verdict['run_id'], 'flag' => $verdict['high_risk'], 'scores' => $verdict['scores'],
        ]);
    }

    // ── Stage 2: cover (blogs) ──────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function coverStage(array $row): void
    {
        $id      = (int) $row['id'];
        $itemId  = (int) $row['content_item_id'];
        $profile = $this->ensureBlogProfiles($itemId);
        $versionId = (int) ($this->item($itemId)['current_version_id'] ?? 0);

        // An operator may have attached a cover meanwhile.
        if (trim((string) ($profile['featured_image_reference'] ?? '')) !== '') {
            $this->recordCover($itemId, $versionId, true, 'Cover image present');
            $this->submissions->update($id, [
                'status'    => RoutineSubmissions::APPROVED,
                'cover_url' => $profile['featured_image_reference'],
                'cover_alt' => $profile['featured_image_alt'] ?? null,
                'cover_status' => $row['cover_status'] ?: 'existing',
            ]);

            return;
        }

        $sub    = (array) ($row['submission_json'] ?? []);
        $result = ($this->covers ?? new RoutineCoverService())->provide($itemId, isset($sub['cover_prompt']) ? (string) $sub['cover_prompt'] : null);

        if ($result['ok']) {
            $this->recordCover($itemId, $versionId, true, $result['source'] === 'generated' ? 'Cover image generated (GPT Image, AICOUNTLY-branded)' : 'Cover image assigned from the gallery');
            $this->submissions->update($id, [
                'status'         => RoutineSubmissions::APPROVED,
                'cover_status'   => $result['source'],
                'cover_url'      => $result['url'],
                'cover_alt'      => $result['alt'],
                'cover_attempts' => (int) $row['cover_attempts'] + 1,
                'last_error'     => $result['generation_error'] !== null ? 'Cover generation failed, gallery used: ' . $result['generation_error'] : null,
            ]);

            return;
        }

        // No cover. Publish anyway only if the readiness gate allows it (the
        // cover rule is off, or an operator waived it for this article);
        // otherwise retry hourly until generation or the gallery succeeds.
        $this->recordCover($itemId, $versionId, false, 'No cover could be sourced: ' . (string) $result['reason']);
        $readiness = (new BlogReadinessService())->evaluate($itemId);
        $coverBlocks = array_filter($readiness['blocking'] ?? [], static fn (string $b) => str_contains($b, 'Featured image') || str_contains($b, 'critical validation'));

        $this->submissions->update($id, [
            'status'                => $coverBlocks === [] ? RoutineSubmissions::APPROVED : RoutineSubmissions::AWAITING_COVER,
            'cover_status'          => $coverBlocks === [] ? 'none_allowed' : 'failed',
            'cover_attempts'        => (int) $row['cover_attempts'] + 1,
            'next_cover_attempt_at' => date('Y-m-d H:i:s', time() + 3600),
            'last_error'            => mb_substr((string) $result['reason'], 0, 1000),
        ]);
    }

    private function recordCover(int $itemId, int $versionId, bool $ok, string $message): void
    {
        $required = (new BlogFeatureFlags())->isEnabled('require_cover_image');
        (new ContentValidationService())->storeResult($itemId, 'cover_image', $ok ? 'passed' : ($required ? 'failed' : 'warning'), [
            'version_id' => $versionId > 0 ? $versionId : null,
            'message'    => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * SEO + publication profile with the routine author, filling only what
     * is missing.
     *
     * @return array<string, mixed> the publication profile
     */
    private function ensureBlogProfiles(int $itemId): array
    {
        (new WorkBlockService())->ensurePublicationProfilesForItem($itemId);
        $profile = $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)->get()->getRowArray() ?? [];

        $author = trim((string) ReachConfig::get('automation.blog.author_name', 'AICOUNTLY Editorial Team')) ?: 'AICOUNTLY Editorial Team';
        $current = trim((string) ($profile['author_reference'] ?? ''));
        // 'aicountly-editorial' is the pipeline's placeholder reference; the
        // public page prints author_name verbatim, so give it the real name.
        if ($current === '' || $current === 'aicountly-editorial') {
            $this->db->table('reach_blog_publication_profiles')->where('content_item_id', $itemId)
                ->update(['author_reference' => mb_substr($author, 0, 255), 'updated_at' => date('Y-m-d H:i:s')]);
            $profile['author_reference'] = $author;
        }

        return $profile;
    }

    // ── Stage 3: publish ────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function publishStage(array $row): void
    {
        $id     = (int) $row['id'];
        $type   = (string) $row['content_type'];
        $itemId = (int) $row['content_item_id'];

        $flag = RoutineFlags::explain($type . '.auto_publish');
        if (! $flag['enabled']) {
            $this->submissions->update($id, [
                'next_attempt_at' => date('Y-m-d H:i:s', time() + 900),
                'last_error'      => $flag['source'] === 'env_kill_switch'
                    ? "Approved; auto-publish is off because {$flag['kill_switch']}=false in the server .env."
                    : 'Approved; auto-publish is off in Settings → AI & Automation. Publish it by hand or switch auto-publish on.',
            ]);

            return;
        }

        $item = $this->item($itemId);
        if (strtolower((string) $item['approval_status']) !== 'approved') {
            $this->submissions->update($id, ['status' => RoutineSubmissions::PUBLISH_FAILED, 'last_error' => 'The approval was revoked; not publishing.']);

            return;
        }
        $versionId = (int) $item['current_version_id'];

        if ($type === 'blog') {
            $this->ensureBlogProfiles($itemId);
            // A checksum-bound approval of exactly this version, or nothing goes out.
            if (! (new BlogContentApprovalService())->verifyForPublication($itemId, $versionId)) {
                $this->submissions->update($id, ['status' => RoutineSubmissions::PUBLISH_FAILED, 'last_error' => 'The current version no longer matches the vetted, approved text; not publishing.']);

                return;
            }
        }

        $attempts = (int) $row['publish_attempts'] + 1;
        try {
            $connection   = (new PublicationConnectionService())->resolveBlogConnectionKey();
            $deploymentId = (new PublicationDeploymentService())->enqueuePublication($itemId, $versionId, $connection, 'publish');
        } catch (\Throwable $e) {
            $this->submissions->update($id, [
                'status'           => $attempts >= self::MAX_PUBLISH_ATTEMPTS * 4 ? RoutineSubmissions::PUBLISH_FAILED : RoutineSubmissions::PUBLISH_BLOCKED,
                'publish_attempts' => $attempts,
                'next_attempt_at'  => date('Y-m-d H:i:s', time() + 1800),
                'last_error'       => 'Publish refused: ' . mb_substr($e->getMessage(), 0, 900),
            ]);

            return;
        }

        if ($type === 'blog') {
            $this->walk(new BlogStateMachine(), $itemId, [BlogStateMachine::PUBLISH_QUEUED, BlogStateMachine::PUBLISHING], [
                'content_version_id' => $versionId, 'deployment_id' => $deploymentId, 'reason' => 'routine_auto_publish',
                // The deployment is already queued; a PUBLISH_BLOG block would
                // only wait for the overnight dispatch window and re-send it.
                'skip_successor_work_block' => true,
            ]);
        }

        $this->submissions->update($id, [
            'status'           => RoutineSubmissions::PUBLISHING,
            'deployment_id'    => $deploymentId,
            'publish_attempts' => $attempts,
            'next_attempt_at'  => null,
            'last_error'       => null,
        ]);
    }

    // ── Stage 4: verify ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function verifyStage(array $row): void
    {
        $id         = (int) $row['id'];
        $deployment = $this->db->table('reach_publication_deployments')->where('id', (int) $row['deployment_id'])->get()->getRowArray();
        if (! $deployment) {
            $this->submissions->update($id, ['status' => RoutineSubmissions::PUBLISH_BLOCKED, 'last_error' => 'Deployment record missing; re-queuing.']);

            return;
        }

        $status = (string) $deployment['status'];
        if ($status === 'failed' || $status === 'cancelled') {
            $attempts = (int) $row['publish_attempts'];
            $this->submissions->update($id, [
                'status'          => $attempts >= self::MAX_PUBLISH_ATTEMPTS ? RoutineSubmissions::PUBLISH_FAILED : RoutineSubmissions::PUBLISH_BLOCKED,
                'next_attempt_at' => date('Y-m-d H:i:s', time() + 900),
                'last_error'      => 'Public site refused the publish: ' . trim((string) ($deployment['error_category'] ?? '') . ' ' . (string) ($deployment['redacted_error'] ?? '')),
            ]);
            $this->revertBlogToApproved($row);

            return;
        }
        if (! in_array($status, ['published', 'verified'], true) || empty($deployment['public_content_id'])) {
            return; // the worker has not sent it yet
        }

        $response = PublicSitePublisherFactory::make()->getVerification((int) $deployment['public_content_id']);
        if (! ($response['success'] ?? false)) {
            $this->submissions->update($id, ['next_attempt_at' => date('Y-m-d H:i:s', time() + 900), 'last_error' => 'Verification call failed: ' . (string) ($response['safe_error_message'] ?? 'unknown')]);

            return;
        }
        if (! hash_equals((string) ($deployment['payload_checksum'] ?? ''), (string) ($response['payload_checksum'] ?? ''))) {
            $this->submissions->update($id, ['next_attempt_at' => date('Y-m-d H:i:s', time() + 900), 'last_error' => 'The public copy does not match what was published (checksum mismatch).']);

            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_publication_deployments')->where('id', $deployment['id'])->update(['status' => 'verified', 'updated_at' => $now]);

        $itemId = (int) $row['content_item_id'];
        if ($row['content_type'] === 'blog') {
            $this->walk(new BlogStateMachine(), $itemId, [BlogStateMachine::PUBLISHED, BlogStateMachine::LIVE], ['deployment_id' => $deployment['id'], 'reason' => 'routine_publication_verified']);
        } else {
            $this->db->table('reach_content_items')->where('id', $itemId)->update([
                'workflow_status' => 'published',
                'published_at'    => $now,
                'updated_at'      => $now,
            ]);
        }

        $this->submissions->update($id, [
            'status'       => RoutineSubmissions::LIVE,
            'published_at' => $deployment['completed_at'] ?? $now,
            'verified_at'  => $now,
            'last_error'   => null,
        ]);
        AuditLogger::record($row['content_type'] === 'blog' ? 'blog.routine_live' : 'kb.routine_live', [
            'content_item_id' => $itemId, 'deployment_id' => (int) $deployment['id'], 'canonical_url' => $deployment['canonical_url'] ?? null,
        ]);
    }

    /**
     * A refused deployment leaves a blog in "publishing"; put it back to
     * approved so the next attempt can walk publish_queued → publishing again.
     *
     * @param array<string, mixed> $row
     */
    private function revertBlogToApproved(array $row): void
    {
        if ($row['content_type'] !== 'blog') {
            return;
        }
        $sm = new BlogStateMachine();
        $this->walk($sm, (int) $row['content_item_id'], [BlogStateMachine::FAILED, BlogStateMachine::BLOCKED, BlogStateMachine::APPROVED], ['reason' => 'routine_publish_refused']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Walk a blog through the given states in order, skipping ones it is
     * already in or past. Unreachable steps are logged, not fatal: the
     * submission row, not the workflow column, is what drives this pipeline.
     *
     * @param list<string>        $states
     * @param array<string, mixed> $meta
     */
    private function walk(BlogStateMachine $sm, int $itemId, array $states, array $meta): void
    {
        foreach ($states as $state) {
            $current = strtolower((string) ($this->item($itemId)['workflow_status'] ?? ''));
            if ($current === $state || ! $sm->canTransition($current, $state)) {
                continue;
            }
            try {
                $sm->transition($itemId, $state, null, $meta);
            } catch (\Throwable $e) {
                log_message('warning', "[RoutinePipeline] item {$itemId}: {$current} -> {$state} refused: " . $e->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function item(int $itemId): array
    {
        $item = $this->db->table('reach_content_items')->where('id', $itemId)->get()->getRowArray();
        if (! $item) {
            throw new \RuntimeException("Content item {$itemId} not found");
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function currentVersion(int $itemId): array
    {
        $item = $this->item($itemId);
        $row  = $this->db->table('reach_content_versions')->where('id', (int) ($item['current_version_id'] ?? 0))->get()->getRowArray();
        if (! $row) {
            $row = $this->db->table('reach_content_versions')->where('content_item_id', $itemId)->orderBy('version_number', 'DESC')->limit(1)->get()->getRowArray();
        }
        if (! $row) {
            throw new \RuntimeException("Content item {$itemId} has no version");
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function seo(int $itemId): array
    {
        return $this->db->table('reach_content_seo_profiles')->where('content_item_id', $itemId)->get()->getRowArray() ?? [];
    }
}
