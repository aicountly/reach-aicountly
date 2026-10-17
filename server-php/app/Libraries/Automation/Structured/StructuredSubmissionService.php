<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Structured;

use App\Libraries\AuditLogger;
use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\ContentVersionService;
use App\Libraries\Publishing\Connector\MockPublicSitePublisher;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Release notes and workflow guides written by the Claude Routines
 * (POST v1/automation/release-notes | workflow-guides).
 *
 * Pipeline (CONTRACTS-WAVE2): validate against the receiver's rules →
 * idempotent on external_key → Gemini vets (release_note / workflow_guide
 * rubric) → a `revise` is re-vetted once → on `approve` the system approver
 * signs it off and it is published to aicountly.com with its own content
 * type → the receiver's verification must report `live` → the public URL is
 * returned. `reject` is stored with the reasons and never published.
 * VettingUnavailableException leaves the submission pending_vetting and
 * the sweep (reach.structured_content_sweep, every 15 minutes) retries it;
 * a failed publish is retried by the same sweep with backoff.
 *
 * Statuses: pending_vetting → approved → published, or rejected, failed
 * (publish did not go live; retried while the cause is transient) and
 * superseded (a newer submission replaced it before it went live).
 */
final class StructuredSubmissionService
{
    public const TYPES = ['release_note', 'workflow_guide'];

    public const MAX_PUBLISH_ATTEMPTS = 10;

    /** Publish failures the sweep retries; everything else waits for an operator. */
    public const RETRYABLE_CATEGORIES = ['rate_limited', 'timeout', 'network_error', 'server_error', 'configuration_error', 'not_live', 'unknown_error'];

    /** Release-note statuses that hold a version (a new note must go above them). */
    private const RELEASE_HOLDS_VERSION = ['pending_vetting', 'approved', 'published'];

    private const LOCK_NAMESPACE = 50_017;

    private BaseConnection $db;
    private ContentVettingService $vetting;
    private PublicationDeploymentService $deployments;

    public function __construct(?ContentVettingService $vetting = null, ?PublicationDeploymentService $deployments = null, ?BaseConnection $db = null)
    {
        $this->db          = $db ?? Database::connect();
        $this->vetting     = $vetting ?? new ContentVettingService();
        $this->deployments = $deployments ?? new PublicationDeploymentService();
    }

    public static function enabled(string $contentType): bool
    {
        return (bool) ReachConfig::get("automation.{$contentType}.enabled", true);
    }

    // ── Submissions ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $in
     * @return array{http: int, data: array<string, mixed>}
     * @throws SubmissionRefused
     */
    public function submitReleaseNote(array $in): array
    {
        $this->assertEnabled('release_note');
        $externalKey = $this->externalKey($in);

        $errors  = [];
        $product = StructuredPayloadValidator::productSlug($in['product_slug'] ?? null, $errors);
        $version = ReleaseVersion::parse((string) ($in['version'] ?? ''));
        if ($version === null) {
            $errors[] = 'version: required, MAJOR.MINOR or MAJOR.MINOR.PATCH (for example "3.5" or "3.4.1").';
        }
        $source = $this->releaseSource($in['source'] ?? null, $product, $errors);
        if ($errors !== []) {
            throw new SubmissionRefused('The release note is not valid.', 422, $errors);
        }

        [$clean, $errors] = StructuredPayloadValidator::validate('release_note', [
            'slug'         => $version->slug(ProductCatalog::releaseSlugPrefix($product)),
            'product_slug' => $product,
            'version'      => (string) $version,
            'title'        => $in['title'] ?? null,
            'date_iso'     => $in['date_iso'] ?? null,
            'intro'        => $in['intro'] ?? null,
            'sections'     => $in['sections'] ?? null,
        ]);
        if ($clean === null) {
            throw new SubmissionRefused('The release note is not valid.', 422, $errors);
        }
        $latest = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        if ($clean['date_iso'] > $latest) {
            throw new SubmissionRefused('The release note is not valid.', 422, ['date_iso: must not be in the future.']);
        }

        $sha = $this->sha($clean);
        if (($replay = $this->replay('release_note', $externalKey, $sha)) !== null) {
            return $replay;
        }

        $this->assertQuota('release_note');
        $floor = $this->releaseFloor($product);
        if ($floor !== null && ($version->compare($floor['version']) <= 0 || ! $version->isNextStepFrom($floor['version']))) {
            throw new SubmissionRefused(
                "Version {$version} is not accepted for {$product}: the last " . $floor['state'] . " version is {$floor['version']}. "
                . 'Use the next fix, feature or major version.',
                409,
                ['last_version' => (string) $floor['version'], 'last_version_state' => $floor['state'], 'allowed_next' => $floor['version']->nextSteps()],
            );
        }

        $id = $this->insert([
            'content_type'   => 'release_note',
            'external_key'   => $externalKey,
            'product_slug'   => $product,
            'public_slug'    => $clean['slug'],
            'version'        => (string) $version,
            'version_sort'   => $version->sortKey(),
            'title'          => $clean['title'],
            'payload_json'   => $this->json($clean),
            'payload_sha256' => $sha,
            'source_json'    => $this->json($source),
            'source_repo'    => $source['repo'],
            'source_to_ref'  => $source['to_ref'],
        ], fn () => $this->db->table('reach_structured_content_submissions')
            // A note that failed to go live earlier is replaced by this one.
            ->where('content_type', 'release_note')
            ->where('product_slug', $product)
            ->where('status', 'failed')
            ->where('version_sort <=', $version->sortKey()));

        return $this->respond($this->process($id));
    }

    /**
     * @param array<string, mixed> $in
     * @return array{http: int, data: array<string, mixed>}
     * @throws SubmissionRefused
     */
    public function submitWorkflowGuide(array $in): array
    {
        $this->assertEnabled('workflow_guide');
        $externalKey = $this->externalKey($in);

        [$clean, $errors] = StructuredPayloadValidator::validate('workflow_guide', $in);
        $source = $this->guideSource($in['source'] ?? null, $errors);
        if ($clean === null || $errors !== []) {
            throw new SubmissionRefused('The workflow guide is not valid.', 422, $errors);
        }

        $sha = $this->sha($clean);
        if (($replay = $this->replay('workflow_guide', $externalKey, $sha)) !== null) {
            return $replay;
        }

        $live = $this->db->table('reach_structured_content_submissions')
            ->where('content_type', 'workflow_guide')
            ->where('product_slug', $clean['product_slug'])
            ->where('public_slug', $clean['slug'])
            ->where('status', 'published')
            ->orderBy('id', 'DESC')->limit(1)
            ->get()->getRowArray();
        if ($live && $live['payload_sha256'] === $sha) {
            return ['http' => 200, 'data' => ['status' => 'unchanged', 'message' => 'This guide is already published with exactly this content.'] + $this->view($live)];
        }

        $this->assertQuota('workflow_guide');
        $id = $this->insert([
            'content_type'   => 'workflow_guide',
            'external_key'   => $externalKey,
            'product_slug'   => $clean['product_slug'],
            'public_slug'    => $clean['slug'],
            'title'          => $clean['title'],
            'payload_json'   => $this->json($clean),
            'payload_sha256' => $sha,
            'source_json'    => $source === null ? null : $this->json($source),
            'source_repo'    => $source['repo'] ?? null,
            'source_to_ref'  => $source['ref'] ?? null,
        ], fn () => $this->db->table('reach_structured_content_submissions')
            // The newest submission for a guide wins; older ones still in flight are dropped.
            ->where('content_type', 'workflow_guide')
            ->where('product_slug', $clean['product_slug'])
            ->where('public_slug', $clean['slug'])
            ->whereIn('status', ['pending_vetting', 'approved', 'failed']));

        return $this->respond($this->process($id));
    }

    // ── Pipeline ────────────────────────────────────────────────────────────

    /**
     * Move one submission as far as it can go now: vet it if pending, publish
     * it if approved or failed. Concurrent callers (the request, the sweep,
     * an operator's retry) are serialised per row; a caller that cannot take
     * the lock just reports the current state.
     *
     * @return array<string, mixed> the submission's view
     */
    public function process(int $id): array
    {
        $row = $this->find($id);
        if (! $this->lock($id)) {
            return $this->view($row);
        }

        try {
            $row = $this->find($id);
            if ($row['status'] === 'pending_vetting') {
                $row = $this->vet($row);
            }
            if (in_array($row['status'], ['approved', 'failed'], true)) {
                $row = $this->publish($row);
            }
        } finally {
            $this->unlock($id);
        }

        return $this->view($row);
    }

    /**
     * Operator retry (Publishing → Release notes / Workflow guides). A
     * rejection is final — the vetting model's decision is not re-asked.
     *
     * @return array<string, mixed>
     * @throws SubmissionRefused
     */
    public function retry(int $id, string $contentType): array
    {
        $row = $this->db->table('reach_structured_content_submissions')
            ->where('id', $id)->where('content_type', $contentType)->get()->getRowArray();
        if (! $row) {
            throw new SubmissionRefused('Submission not found.', 404);
        }
        if (! in_array($row['status'], ['pending_vetting', 'approved', 'failed'], true)) {
            throw new SubmissionRefused("A {$row['status']} submission cannot be retried.", 409);
        }
        if (! self::enabled($contentType)) {
            throw new SubmissionRefused($this->disabledMessage($contentType), 409);
        }

        $this->update($id, ['next_attempt_at' => null]);
        AuditLogger::record('structured_content.retry_requested', ['submission_id' => $id, 'content_type' => $contentType, 'status' => $row['status']]);

        return $this->process($id);
    }

    /**
     * Scheduled retry of submissions left pending (vetting unavailable) or
     * failed for a transient reason. Types switched off in Settings are left
     * alone until switched back on.
     *
     * @return array<string, mixed>
     */
    public function sweep(int $limit = 20): array
    {
        $now     = $this->now();
        $settled = date('Y-m-d H:i:s', time() - 600);
        $types   = array_values(array_filter(self::TYPES, [self::class, 'enabled']));
        $summary = ['considered' => 0, 'by_status' => [], 'disabled_types' => array_values(array_diff(self::TYPES, $types))];
        if ($types === []) {
            return $summary;
        }

        $rows = $this->db->query(
            "SELECT id FROM reach_structured_content_submissions
             WHERE content_type IN ?
               AND (
                    (status = 'pending_vetting' AND COALESCE(next_attempt_at, created_at + INTERVAL '5 minutes') <= ?)
                 OR (status = 'approved' AND updated_at <= ?)
                 OR (status = 'failed' AND next_attempt_at IS NOT NULL AND next_attempt_at <= ?)
               )
             ORDER BY id ASC
             LIMIT ?",
            [$types, $now, $settled, $now, max(1, $limit)]
        )->getResultArray();

        foreach ($rows as $r) {
            $summary['considered']++;
            try {
                $status = $this->process((int) $r['id'])['status'];
            } catch (\Throwable $e) {
                log_message('error', '[StructuredSubmissions] sweep #' . $r['id'] . ': ' . $e->getMessage());
                $status = 'error';
            }
            $summary['by_status'][$status] = ($summary['by_status'][$status] ?? 0) + 1;
        }

        return $summary;
    }

    /** @param array<string, mixed> $row */
    private function vet(array $row): array
    {
        $type = (string) $row['content_type'];
        if (! self::enabled($type)) {
            return $row;
        }

        $payload  = $this->decode($row['payload_json']);
        $identity = $this->identity($type, $payload);
        $draft    = array_diff_key($payload, $identity);
        $context  = $this->vettingContext($row);
        $ref      = $type . ':' . $row['external_key'];
        $runs     = $this->decode($row['vetting_run_ids']);

        try {
            $first  = $this->vetting->vet($type, $draft, $context, $ref);
            $runs[] = $first->runId;
            $result = $first;
            $reasons = $first->reasons;
            if ($first->decision === VettingResult::REVISE) {
                $second  = $this->vetting->vet($type, $first->final, $context, $ref);
                $runs[]  = $second->runId;
                $reasons = array_merge($reasons, $second->reasons);
                $result  = $second;
            }
        } catch (VettingUnavailableException $e) {
            $attempts = (int) $row['attempt_count'] + 1;
            $this->update((int) $row['id'], [
                'attempt_count'   => $attempts,
                'error_category'  => 'vetting_unavailable',
                'last_error'      => mb_substr($e->getMessage(), 0, 1000),
                'next_attempt_at' => $this->backoff($attempts, 60),
                'vetting_run_ids' => $this->json(array_values(array_filter($runs))),
            ]);
            return $this->find((int) $row['id']);
        }

        $runs  = array_values(array_filter($runs));
        $runId = $result->runId;

        if ($result->decision === VettingResult::REVISE) {
            $reasons[] = 'Not published: the vetting model still asked for changes after one revision.';
            return $this->reject($row, $reasons, $runId, $runs);
        }
        if ($result->decision === VettingResult::REJECT) {
            return $this->reject($row, $reasons, $runId, $runs);
        }

        [$clean, $errors] = StructuredPayloadValidator::validate($type, array_merge($result->final, $identity));
        if ($clean === null) {
            $reasons[] = 'Not published: the vetted text does not fit aicountly.com\'s format rules — ' . implode(' ', array_slice($errors, 0, 5));
            return $this->reject($row, $reasons, $runId, $runs);
        }

        return $this->approve($row, $clean, $reasons, $runId, $runs);
    }

    /**
     * @param list<string>   $reasons
     * @param list<int>      $runs
     */
    private function reject(array $row, array $reasons, ?int $runId, array $runs): array
    {
        $this->update((int) $row['id'], [
            'status'           => 'rejected',
            'vetting_decision' => 'reject',
            'vetting_run_id'   => $runId,
            'vetting_run_ids'  => $this->json($runs),
            'reasons_json'     => $this->json(array_values($reasons)),
            'error_category'   => null,
            'last_error'       => null,
            'next_attempt_at'  => null,
        ]);
        AuditLogger::record('structured_content.rejected', [
            'submission_id'  => (int) $row['id'],
            'content_type'   => $row['content_type'],
            'product_slug'   => $row['product_slug'],
            'vetting_run_id' => $runId,
        ]);

        return $this->find((int) $row['id']);
    }

    /**
     * Sign the vetted payload off under the system approver: a content
     * version holding exactly what will be sent, the item marked approved by
     * "Reach System Bot", and the vetting run that decided it on the row.
     *
     * @param array<string, mixed> $clean
     * @param list<string>         $reasons
     * @param list<int>            $runs
     */
    private function approve(array $row, array $clean, array $reasons, ?int $runId, array $runs): array
    {
        $type    = (string) $row['content_type'];
        $userId  = SystemApprover::userId();
        $actorId = SystemApprover::actorId();
        $now     = $this->now();

        $this->db->transStart();
        $itemId  = $this->contentItemFor($row, $clean);
        $version = (new ContentVersionService())->createVersion($itemId, [
            'title'              => $clean['title'],
            'summary'            => $type === 'release_note' ? $clean['intro'] : $clean['blurb'],
            'body_plain_text'    => $this->plainText($type, $clean),
            'structured_payload' => $clean,
        ], [
            'type'    => 'bot',
            'service' => 'reach:automation_' . $type,
        ], 'Routine submission #' . $row['id'] . ' approved after vetting' . ($runId ? " (vetting run #{$runId})" : ''));

        $this->db->table('reach_content_items')->where('id', $itemId)->update([
            'title'           => mb_substr($clean['title'], 0, 500),
            'summary'         => $type === 'release_note' ? $clean['intro'] : $clean['blurb'],
            'workflow_status' => 'approved',
            'approval_status' => 'approved',
            'approved_at'     => $now,
            'approved_by'     => $actorId,
            'updated_at'      => $now,
        ]);

        $this->update((int) $row['id'], [
            'status'              => 'approved',
            'vetting_decision'    => 'approve',
            'vetting_run_id'      => $runId,
            'vetting_run_ids'     => $this->json($runs),
            'reasons_json'        => $this->json(array_values($reasons)),
            'final_json'          => $this->json($clean),
            'approved_by_user_id' => $userId,
            'approved_at'         => $now,
            'content_item_id'     => $itemId,
            'content_version_id'  => (int) $version['id'],
            'attempt_count'       => 0,
            'error_category'      => null,
            'last_error'          => null,
            'next_attempt_at'     => null,
        ]);
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Could not record the approval of submission #' . $row['id']);
        }

        AuditLogger::record('structured_content.auto_approved', [
            'submission_id'      => (int) $row['id'],
            'content_type'       => $type,
            'product_slug'       => $row['product_slug'],
            'content_item_id'    => $itemId,
            'content_version_id' => (int) $version['id'],
            'vetting_run_id'     => $runId,
            'approver'           => SystemApprover::EMAIL,
        ], $userId);

        return $this->find((int) $row['id']);
    }

    /** @param array<string, mixed> $row */
    private function publish(array $row): array
    {
        $type = (string) $row['content_type'];
        $id   = (int) $row['id'];
        if (! self::enabled($type) || empty($row['content_item_id']) || empty($row['content_version_id'])) {
            return $row;
        }

        $attempt = (int) $row['attempt_count'] + 1;

        // Production honesty: a mock publisher "publishes" nothing. Never let
        // it turn a production submission into a reported publish.
        if ($this->mockPublisherInProduction()) {
            return $this->failPublish($id, $attempt, 'configuration_error', 'Publishing is in mock mode (REACH_PUB_MOCK) on a production server; nothing was sent to aicountly.com.');
        }

        try {
            $deploymentId = $this->deployments->createStructuredDeployment(
                (int) $row['content_item_id'],
                (int) $row['content_version_id'],
                SystemApprover::actorId(),
            );
            $this->update($id, ['deployment_id' => $deploymentId, 'attempt_count' => $attempt]);
            $this->deployments->processDeployment($deploymentId);
        } catch (\Throwable $e) {
            log_message('error', '[StructuredSubmissions] publish #' . $id . ': ' . $e->getMessage());
            return $this->failPublish($id, $attempt, 'unknown_error', 'The publish could not be started: ' . mb_substr($e->getMessage(), 0, 300));
        }

        $deployment = $this->db->table('reach_publication_deployments')->where('id', $deploymentId)->get()->getRowArray() ?? [];
        if (($deployment['status'] ?? '') !== 'published' || empty($deployment['canonical_url'])) {
            return $this->failPublish(
                $id,
                $attempt,
                (string) ($deployment['error_category'] ?? 'unknown_error'),
                (string) ($deployment['redacted_error'] ?? 'The publish did not complete.'),
            );
        }

        $now = $this->now();
        $this->update($id, [
            'status'            => 'published',
            'public_url'        => $deployment['canonical_url'],
            'public_content_id' => $deployment['public_content_id'],
            'published_at'      => $now,
            'error_category'    => null,
            'last_error'        => null,
            'next_attempt_at'   => null,
        ]);
        $this->db->table('reach_content_items')->where('id', (int) $row['content_item_id'])->update([
            'workflow_status' => 'published',
            'published_at'    => $now,
            'updated_at'      => $now,
        ]);

        if ($type === 'release_note') {
            // An older note still waiting would otherwise go live after this one.
            $this->db->table('reach_structured_content_submissions')
                ->where('content_type', 'release_note')
                ->where('product_slug', $row['product_slug'])
                ->where('version_sort <', (int) $row['version_sort'])
                ->whereIn('status', ['pending_vetting', 'approved', 'failed'])
                ->update(['status' => 'superseded', 'superseded_by_id' => $id, 'next_attempt_at' => null, 'updated_at' => $now]);
        }

        AuditLogger::record('structured_content.published', [
            'submission_id' => $id,
            'content_type'  => $type,
            'product_slug'  => $row['product_slug'],
            'deployment_id' => $deploymentId,
            'public_url'    => $deployment['canonical_url'],
        ], SystemApprover::userId());

        return $this->find($id);
    }

    private function failPublish(int $id, int $attempt, string $category, string $message): array
    {
        $retry = in_array($category, self::RETRYABLE_CATEGORIES, true) && $attempt < self::MAX_PUBLISH_ATTEMPTS;
        $this->update($id, [
            'status'          => 'failed',
            'attempt_count'   => $attempt,
            'error_category'  => $category,
            'last_error'      => mb_substr($message, 0, 2000),
            'next_attempt_at' => $retry ? $this->backoff($attempt, 360) : null,
        ]);

        return $this->find($id);
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    /**
     * @param array{status?: string, product_slug?: string, limit?: int|string, offset?: int|string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(string $contentType, array $filters = []): array
    {
        $build = function () use ($contentType, $filters) {
            $q = $this->db->table('reach_structured_content_submissions')->where('content_type', $contentType);
            if (! empty($filters['status'])) {
                $q->where('status', (string) $filters['status']);
            }
            if (! empty($filters['product_slug'])) {
                $q->where('product_slug', (string) $filters['product_slug']);
            }
            return $q;
        };

        $limit  = min(200, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $rows   = $build()->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return [
            'items' => array_map([$this, 'view'], $rows),
            'total' => (int) $build()->countAllResults(),
        ];
    }

    /** @return array<string, mixed> */
    public function view(array $row): array
    {
        $product = ProductCatalog::get((string) $row['product_slug']);
        $status  = (string) $row['status'];

        return [
            'id'                 => (int) $row['id'],
            'content_type'       => $row['content_type'],
            'external_key'       => $row['external_key'],
            'product_slug'       => $row['product_slug'],
            'product_name'       => $product['name'] ?? $row['product_slug'],
            'slug'               => $row['public_slug'],
            'version'            => $row['version'],
            'title'              => $row['title'],
            'status'             => $status,
            'vetting_decision'   => $row['vetting_decision'],
            'vetting_run_id'     => $row['vetting_run_id'] !== null ? (int) $row['vetting_run_id'] : null,
            'vetting_run_ids'    => $this->decode($row['vetting_run_ids']),
            'reasons'            => $this->decode($row['reasons_json']),
            'approved_by'        => $row['approved_by_user_id'] !== null ? 'Reach System Bot' : null,
            'approved_at'        => $row['approved_at'],
            'public_url'         => $row['public_url'],
            'published_at'       => $row['published_at'],
            'attempt_count'      => (int) $row['attempt_count'],
            'error_category'     => $row['error_category'],
            'last_error'         => $row['last_error'],
            'next_attempt_at'    => $row['next_attempt_at'],
            'retryable'          => in_array($status, ['pending_vetting', 'approved', 'failed'], true),
            'source'             => $row['source_json'] !== null ? $this->decode($row['source_json']) : null,
            'content_item_id'    => $row['content_item_id'] !== null ? (int) $row['content_item_id'] : null,
            'deployment_id'      => $row['deployment_id'] !== null ? (int) $row['deployment_id'] : null,
            'created_at'         => $row['created_at'],
            'updated_at'         => $row['updated_at'],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The highest release-note version that a new one must follow: Reach's
     * published or in-flight notes, or the hand-written release on the site.
     *
     * @return array{version: ReleaseVersion, state: string}|null
     */
    public function releaseFloor(string $productSlug): ?array
    {
        $row = $this->db->table('reach_structured_content_submissions')
            ->select('version, status')
            ->where('content_type', 'release_note')
            ->where('product_slug', $productSlug)
            ->whereIn('status', self::RELEASE_HOLDS_VERSION)
            ->orderBy('version_sort', 'DESC')->limit(1)
            ->get()->getRowArray();

        $floor = null;
        if ($row && ($v = ReleaseVersion::parse((string) $row['version'])) !== null) {
            $floor = ['version' => $v, 'state' => $row['status'] === 'published' ? 'published' : 'submitted (' . $row['status'] . ')'];
        }

        $hand = ProductCatalog::get($productSlug)['hand_release'] ?? null;
        if ($hand !== null && ($hv = ReleaseVersion::parse($hand['version'])) !== null
            && ($floor === null || $hv->compare($floor['version']) > 0)) {
            $floor = ['version' => $hv, 'state' => 'published'];
        }

        return $floor;
    }

    /** @param array<string, mixed> $in */
    private function externalKey(array $in): string
    {
        $key = trim((string) ($in['external_key'] ?? ''));
        if ($key === '' || strlen($key) > 191 || preg_match('/^[A-Za-z0-9._:\/@-]+$/', $key) !== 1) {
            throw new SubmissionRefused('The submission is not valid.', 422, ['external_key: required; up to 191 letters, digits and . _ : / @ - (for example "books:3.5:2026-10-17").']);
        }

        return $key;
    }

    /**
     * @param list<string> $errors
     * @return array{repo: string, from_ref: ?string, to_ref: string, commits_count: int}
     */
    private function releaseSource(mixed $source, string $product, array &$errors): array
    {
        $source = is_array($source) ? $source : [];
        $repo   = trim((string) ($source['repo'] ?? ''));
        $repo   = (string) preg_replace('#^(https://github\.com/)?aicountly/#i', '', $repo);
        $repo   = (string) preg_replace('#\.git$#', '', $repo);
        $expect = ProductCatalog::get($product)['repo'] ?? null;
        if ($repo === '') {
            $errors[] = 'source.repo: required (the product repository the note was written from).';
        } elseif ($expect !== null && strcasecmp($repo, $expect) !== 0) {
            $errors[] = "source.repo: {$product} release notes come from aicountly/{$expect}, not {$repo}.";
        }

        $ref = static fn ($v): ?string => is_string($v) && preg_match('/^[A-Za-z0-9._\/-]{4,120}$/', trim($v)) === 1 ? trim($v) : null;
        $to  = $ref($source['to_ref'] ?? null);
        if ($to === null) {
            $errors[] = 'source.to_ref: required, the commit SHA (or tag) the note covers up to.';
        }
        $from = $source['from_ref'] ?? null;
        if ($from !== null && $from !== '' && $ref($from) === null) {
            $errors[] = 'source.from_ref: must be a commit SHA or tag.';
        }
        $count = $source['commits_count'] ?? null;
        if (! is_int($count) || $count < 1) {
            $errors[] = 'source.commits_count: required, the number of commits the note covers (at least 1).';
        }

        return [
            'repo'          => $expect ?? $repo,
            'from_ref'      => $ref($from),
            'to_ref'        => (string) $to,
            'commits_count' => is_int($count) ? $count : 0,
        ];
    }

    /**
     * @param list<string> $errors
     * @return array{repo: ?string, ref: ?string}|null
     */
    private function guideSource(mixed $source, array &$errors): ?array
    {
        if ($source === null) {
            return null;
        }
        if (! is_array($source)) {
            $errors[] = 'source: must be an object {repo, ref}.';
            return null;
        }
        $repo = trim((string) ($source['repo'] ?? ''));
        $ref  = trim((string) ($source['ref'] ?? ''));
        if ($repo !== '' && preg_match('/^[A-Za-z0-9._\/-]{1,120}$/', $repo) !== 1) {
            $errors[] = 'source.repo: a repository name.';
        }
        if ($ref !== '' && preg_match('/^[A-Za-z0-9._\/-]{4,120}$/', $ref) !== 1) {
            $errors[] = 'source.ref: a commit SHA or branch.';
        }

        return ['repo' => $repo !== '' ? $repo : null, 'ref' => $ref !== '' ? $ref : null];
    }

    /**
     * Same key again: the same content is an idempotent replay of the stored
     * submission; different content under a used key is a conflict.
     *
     * @return array{http: int, data: array<string, mixed>}|null
     */
    private function replay(string $type, string $externalKey, string $sha): ?array
    {
        $row = $this->db->table('reach_structured_content_submissions')
            ->where('content_type', $type)->where('external_key', $externalKey)
            ->get()->getRowArray();
        if (! $row) {
            return null;
        }
        if ($row['payload_sha256'] !== $sha) {
            throw new SubmissionRefused("external_key '{$externalKey}' was already used for different content. Use a new key for new content.", 409);
        }

        return ['http' => 200, 'data' => $this->view($row) + ['idempotent_replay' => true]];
    }

    /**
     * Insert a submission. $supersede is the query (same transaction, run
     * first) selecting the older submissions this one replaces; they are
     * marked superseded and pointed at the new row.
     *
     * @param array<string, mixed> $fields
     * @param callable(): \CodeIgniter\Database\BaseBuilder $supersede
     */
    private function insert(array $fields, callable $supersede): int
    {
        $now = $this->now();
        $this->db->transBegin();
        try {
            $old = array_map('intval', array_column($supersede()->select('id')->get()->getResultArray(), 'id'));
            if ($old !== []) {
                $this->db->table('reach_structured_content_submissions')->whereIn('id', $old)
                    ->update(['status' => 'superseded', 'next_attempt_at' => null, 'updated_at' => $now]);
            }
            $this->db->table('reach_structured_content_submissions')->insert($fields + [
                'status'     => 'pending_vetting',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
            if ($old !== []) {
                $this->db->table('reach_structured_content_submissions')->whereIn('id', $old)->update(['superseded_by_id' => $id]);
            }
            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains($e->getMessage(), '23505')) {
                throw new SubmissionRefused('Another submission for this ' . ($fields['content_type'] === 'release_note' ? 'version' : 'guide') . ' is already being processed.', 409);
            }
            throw $e;
        }

        AuditLogger::record('structured_content.submitted', [
            'submission_id' => $id,
            'content_type'  => $fields['content_type'],
            'product_slug'  => $fields['product_slug'],
            'slug'          => $fields['public_slug'],
            'version'       => $fields['version'] ?? null,
            'superseded'    => $old,
        ]);

        return $id;
    }

    /** The reach_content_items row for this public page (one per page, reused across versions). */
    private function contentItemFor(array $row, array $clean): int
    {
        $slug = $row['content_type'] === 'release_note'
            ? 'release-note-' . $row['public_slug']
            : 'workflow-guide-' . $row['product_slug'] . '-' . $row['public_slug'];
        $slug = substr($slug, 0, 200);

        $existing = $this->db->table('reach_content_items')->select('id')->where('slug', $slug)->get()->getRowArray();
        if ($existing) {
            return (int) $existing['id'];
        }

        $now = $this->now();
        $this->db->table('reach_content_items')->insert([
            'content_type'       => $row['content_type'],
            'title'              => mb_substr($clean['title'], 0, 500),
            'slug'               => $slug,
            'summary'            => $row['content_type'] === 'release_note' ? $clean['intro'] : $clean['blurb'],
            'risk_level'         => 'low',
            'creation_source'    => 'automation',
            'workflow_status'    => 'draft',
            'approval_status'    => 'pending',
            'created_actor_type' => 'system',
            'created_by_service' => 'reach:automation_' . $row['content_type'],
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Fields the vetting model may not change: identity and links.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function identity(string $type, array $payload): array
    {
        $keys = $type === 'release_note'
            ? ['slug', 'product_slug', 'version', 'date_iso']
            : ['product_slug', 'slug', 'image_url'];

        return array_intersect_key($payload, array_flip($keys));
    }

    /** @return array<string, mixed> */
    private function vettingContext(array $row): array
    {
        $product = ProductCatalog::get((string) $row['product_slug']);
        $context = [
            'product'      => $product['name'] ?? $row['product_slug'],
            'product_slug' => $row['product_slug'],
            'publishes_to' => $row['content_type'] === 'release_note'
                ? 'aicountly.com "What\'s new" release notes'
                : 'aicountly.com/guides — ' . ProductCatalog::guideUrl((string) $row['product_slug'], (string) $row['public_slug']),
            'format'       => 'Plain text only in every field — no Markdown, no HTML. Keep every field within its current length.',
        ];
        if ($row['content_type'] === 'release_note') {
            $context['version'] = $row['version'];
            $source = $this->decode($row['source_json']);
            $context['source'] = 'Written by the release-notes routine from ' . ($source['commits_count'] ?? '?') . ' commits in the product repository.';
        }

        return $context;
    }

    private function plainText(string $type, array $clean): string
    {
        $parts = [$clean['title']];
        if ($type === 'release_note') {
            $parts[] = $clean['intro'];
            foreach ($clean['sections'] as $s) {
                $parts[] = $s['heading'];
                array_push($parts, ...($s['paragraphs'] ?? []), ...($s['bullets'] ?? []));
            }
        } else {
            array_push($parts, $clean['blurb'], $clean['audience'], $clean['outcome'], ...$clean['prerequisites']);
            foreach ($clean['steps'] as $s) {
                $parts[] = $s['title'] . ': ' . $s['text'] . (isset($s['tip']) ? ' Tip: ' . $s['tip'] : '');
            }
            foreach ($clean['faqs'] as $f) {
                $parts[] = $f['q'] . ' ' . $f['a'];
            }
        }

        return implode("\n\n", $parts);
    }

    private function mockPublisherInProduction(): bool
    {
        $env = strtolower((string) ($_ENV['CI_ENVIRONMENT'] ?? getenv('CI_ENVIRONMENT') ?: (defined('ENVIRONMENT') ? ENVIRONMENT : 'production')));

        return $env === 'production' && PublicSitePublisherFactory::make() instanceof MockPublicSitePublisher;
    }

    private function assertEnabled(string $type): void
    {
        if (! self::enabled($type)) {
            throw new SubmissionRefused($this->disabledMessage($type), 403);
        }
    }

    /** Today's quota (automation.{type}.daily_limit, IST day) — 429 so the routine stops. */
    private function assertQuota(string $type): void
    {
        $limit = (int) ReachConfig::get("automation.{$type}.daily_limit", 40);
        $start = (new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata')))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $used  = $this->db->table('reach_structured_content_submissions')
            ->where('content_type', $type)
            ->where('created_at >=', $start->format('Y-m-d H:i:s'))
            ->countAllResults();
        if ($used >= $limit) {
            throw new SubmissionRefused(
                "Today's limit of {$limit} " . ($type === 'release_note' ? 'release notes' : 'workflow guides')
                . ' is used (Settings → AI & Automation). Stop this run; nothing was stored.',
                429,
                ['daily_limit' => $limit, 'used_today' => $used],
            );
        }
    }

    private function disabledMessage(string $type): string
    {
        $label = $type === 'release_note' ? 'Release-note' : 'Workflow-guide';

        return "{$label} automation is switched off in Reach (Settings → AI & Automation). Nothing was stored; stop this run.";
    }

    /**
     * @param array<string, mixed> $view
     * @return array{http: int, data: array<string, mixed>}
     */
    private function respond(array $view): array
    {
        $http = match ($view['status']) {
            'published' => 201,
            'rejected'  => 200,
            default     => 202,
        };
        $view['message'] = match ($view['status']) {
            'published'       => 'Published and live at ' . $view['public_url'],
            'rejected'        => 'Rejected by vetting; stored with the reasons and not published.',
            'pending_vetting' => 'Stored. Vetting is unavailable right now; Reach will retry and publish if it is approved. Do not resubmit.',
            'failed'          => 'Approved, but the publish did not go live yet' . ($view['next_attempt_at'] ? '; Reach will retry.' : '; an operator needs to look at it in Reach.'),
            default           => 'Stored; Reach will finish publishing it.',
        };

        return ['http' => $http, 'data' => $view];
    }

    private function find(int $id): array
    {
        $row = $this->db->table('reach_structured_content_submissions')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            throw new \RuntimeException("Submission {$id} not found");
        }

        return $row;
    }

    /** @param array<string, mixed> $fields */
    private function update(int $id, array $fields): void
    {
        $this->db->table('reach_structured_content_submissions')->where('id', $id)
            ->update($fields + ['updated_at' => $this->now()]);
    }

    private function lock(int $id): bool
    {
        $row = $this->db->query('SELECT pg_try_advisory_lock(?, ?) AS locked', [self::LOCK_NAMESPACE, $id])->getRowArray();

        return in_array($row['locked'] ?? false, [true, 't', 1, '1'], true);
    }

    private function unlock(int $id): void
    {
        $this->db->query('SELECT pg_advisory_unlock(?, ?)', [self::LOCK_NAMESPACE, $id]);
    }

    private function backoff(int $attempt, int $capMinutes): string
    {
        $minutes = min($capMinutes, 15 * (2 ** max(0, $attempt - 1)));

        return date('Y-m-d H:i:s', time() + $minutes * 60);
    }

    private function sha(array $payload): string
    {
        return hash('sha256', $this->json($payload));
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
