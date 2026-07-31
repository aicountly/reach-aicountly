<?php

namespace App\Libraries\Publishing\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Blog\BlogFeatureFlags;
use App\Libraries\JobService;
use App\Libraries\Publishing\Blog\BlogPublicationPayloadBuilder;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use App\Libraries\Publishing\Connector\PublishingErrorClassifier;
use App\Libraries\Publishing\Seo\PublicationReadinessAggregator;

/**
 * Phase 4 — Publication deployment service.
 *
 * Orchestrates the creation and progression of publication deployments.
 * Uses the Phase 0 job queue for async operations.
 * Human approval is mandatory; this service never approves content.
 */
class PublicationDeploymentService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private PublishingErrorClassifier $classifier;
    private JobService $jobService;
    private BlogFeatureFlags $flags;
    private BlogContentApprovalService $approvals;

    public function __construct(?JobService $jobService = null, ?BlogFeatureFlags $flags = null, ?BlogContentApprovalService $approvals = null)
    {
        $this->db         = \Config\Database::connect();
        $this->classifier = new PublishingErrorClassifier();
        $this->jobService = $jobService ?? new JobService();
        $this->flags       = $flags ?? new BlogFeatureFlags();
        $this->approvals   = $approvals ?? new BlogContentApprovalService();
    }

    /**
     * Enqueue a publication job for a content item.
     *
     * @throws \RuntimeException if readiness check fails or content not approved
     */
    public function enqueuePublication(
        int $contentItemId,
        int $contentVersionId,
        string $connectionKey = 'aicountly_com',
        string $operation = 'publish',
        ?string $scheduledAt = null,
        ?int $createdBy = null
    ): int {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)->get()->getRowArray();

        if (!$item || $item['approval_status'] !== 'approved') {
            throw new \RuntimeException('Content must be human-approved before publishing');
        }

        if ($item['content_type'] === 'blog') {
            $riskLevel = strtoupper((string) ($item['risk_level'] ?? 'LOW'));
            $blogDetails = $this->db->table('reach_content_blog_details')
                ->where('content_item_id', $contentItemId)->get()->getRowArray();
            $riskClass = strtoupper((string) ($blogDetails['risk_class'] ?? $riskLevel));

            if (in_array($riskLevel, ['HIGH', 'CRITICAL'], true) || $riskClass === 'HIGH') {
                if (!$this->approvals->verifyForPublication($contentItemId, $contentVersionId)) {
                    throw new \RuntimeException(
                        'High-risk blog content requires a valid, checksum-matching human approval for this exact version before publishing'
                    );
                }
            }
        }

        $connection = $this->db->table('reach_publication_connections')
            ->where('connection_key', $connectionKey)
            ->where('enabled', true)
            ->get()->getRowArray();

        if (!$connection) {
            throw new \RuntimeException("Publication connection '{$connectionKey}' not found or disabled");
        }

        // Readiness gate
        $aggregator = new PublicationReadinessAggregator();
        $readiness  = $aggregator->evaluate($contentItemId, $item['content_type']);

        if (!$readiness['ready']) {
            throw new \RuntimeException(
                'Content is not ready for publication: ' . implode('; ', $readiness['blocking'])
            );
        }

        $idempotencyKey = "reach-{$contentItemId}-v{$contentVersionId}-{$operation}-" . time();

        $this->db->table('reach_publication_deployments')->insert([
            'content_item_id'    => $contentItemId,
            'content_version_id' => $contentVersionId,
            'connection_id'      => $connection['id'],
            'operation'          => $operation,
            'status'             => 'queued',
            'idempotency_key'    => $idempotencyKey,
            'scheduled_at'       => $scheduledAt,
            'created_by'         => $createdBy,
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $deploymentId = (int) $this->db->insertID();

        $this->enqueuePublicationJob($deploymentId, $idempotencyKey, $scheduledAt);

        AuditLogger::record('publishing.queued', [
            'content_item_id' => $contentItemId,
            'deployment_id'   => $deploymentId,
            'operation'       => $operation,
        ], $createdBy);

        return $deploymentId;
    }

    /**
     * Enqueue a publication job via JobService (testable wiring point).
     */
    public function enqueuePublicationJob(
        int $deploymentId,
        string $idempotencyKey,
        ?string $scheduledAt = null,
        bool $isRetry = false
    ): int {
        $payload = ['deployment_id' => $deploymentId];
        if ($isRetry) {
            $payload['is_retry'] = true;
        }

        $opts = [
            'queue'           => 'publishing',
            'idempotency_key' => $idempotencyKey . ($isRetry ? ':retry' : ''),
            'priority'        => 10,
        ];

        if ($scheduledAt !== null && $scheduledAt !== '') {
            return $this->jobService->enqueueAt(
                'reach.publication',
                $payload,
                $scheduledAt,
                $opts,
            );
        }

        return $this->jobService->enqueue('reach.publication', $payload, $opts);
    }

    /**
     * Process a deployment — called by the job worker.
     *
     * Real flow: build the actual sanitised payload (never an empty one),
     * create-or-update the draft package on the public site, then — only for
     * operation=publish/schedule — make the follow-on call that actually
     * flips the public status. Each HTTP call to the public receiver uses
     * its own idempotency key so a create-draft replay can never be confused
     * with (and short-circuit) the publish call.
     */
    public function processDeployment(int $deploymentId): void
    {
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)->get()->getRowArray();

        if (!$deployment) {
            throw new \RuntimeException("Deployment {$deploymentId} not found");
        }

        if (!in_array($deployment['status'], ['queued', 'sending'], true)) {
            return; // Already processed or cancelled
        }

        $this->updateDeploymentStatus($deploymentId, 'sending');

        $attemptNumber = ((int) $deployment['attempt_count']) + 1;

        $this->db->table('reach_publication_attempts')->insert([
            'deployment_id'  => $deploymentId,
            'attempt_number' => $attemptNumber,
            'status'         => 'sending',
            'started_at'     => date('Y-m-d H:i:s'),
        ]);

        $attemptId = $this->db->insertID();
        $this->updateDeploymentStatus($deploymentId, 'sending', ['latest_attempt_id' => $attemptId, 'attempt_count' => $attemptNumber]);

        $publisher = PublicSitePublisherFactory::make();

        try {
            $builder  = new BlogPublicationPayloadBuilder();
            $payload  = $builder->build((int) $deployment['content_item_id'], (int) $deployment['content_version_id']);
            $checksum = $builder->checksum($payload);

            $existingPublicId = $this->resolvePublicContentId((int) $deployment['content_item_id']);

            $response = $existingPublicId
                ? $publisher->updateDraft($existingPublicId, $this->buildEnvelope($deployment, $payload, $checksum, 'update_draft'))
                : $publisher->createDraft($this->buildEnvelope($deployment, $payload, $checksum, 'create_draft'));

            if (!($response['success'] ?? false)) {
                $this->onFailure($deploymentId, $attemptId, $response['error_category'] ?? 'unknown_error', $response['safe_error_message'] ?? '');
                return;
            }

            $publicContentId = (int) ($response['public_content_id'] ?? $existingPublicId ?? 0);

            if ($deployment['operation'] === 'publish' && $publicContentId > 0) {
                $response = $publisher->publish($publicContentId, $this->buildEnvelope($deployment, $payload, $checksum, 'publish'));
            } elseif ($deployment['operation'] === 'schedule' && $publicContentId > 0 && !empty($deployment['scheduled_at'])) {
                $response = $publisher->schedule(
                    $publicContentId,
                    $this->buildEnvelope($deployment, $payload, $checksum, 'schedule'),
                    (string) $deployment['scheduled_at'],
                );
            }

            if ($response['success'] ?? false) {
                $this->onSuccess($deploymentId, $attemptId, $response, $deployment);
            } else {
                $this->onFailure($deploymentId, $attemptId, $response['error_category'] ?? 'unknown_error', $response['safe_error_message'] ?? '');
            }
        } catch (\Throwable $e) {
            $category = $this->classifier->classifyException($e);
            $this->onFailure($deploymentId, $attemptId, $category, 'Exception during publishing');
        }
    }

    /**
     * Look up the public site's content id for this Reach content item from
     * the most recent deployment that received one, so repeat/updated
     * deployments call updateDraft (not createDraft) and never create a
     * duplicate public record.
     */
    private function resolvePublicContentId(int $contentItemId): ?int
    {
        $row = $this->db->table('reach_publication_deployments')
            ->where('content_item_id', $contentItemId)
            ->where('public_content_id IS NOT NULL', null, false)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $row ? (int) $row['public_content_id'] : null;
    }

    private function onSuccess(int $deploymentId, int $attemptId, array $response, array $deployment): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('reach_publication_attempts')->where('id', $attemptId)->update([
            'status'       => 'accepted',
            'http_status'  => 200,
            'completed_at' => $now,
            'duration_ms'  => 0,
        ]);

        $status = $deployment['operation'] === 'publish' ? 'published'
            : ($deployment['operation'] === 'schedule' ? 'scheduled' : 'accepted');

        $this->db->table('reach_publication_deployments')->where('id', $deploymentId)->update([
            'status'             => $status,
            'public_content_id'  => $response['public_content_id'] ?? null,
            'public_content_uuid'=> $response['public_content_uuid'] ?? null,
            'canonical_url'      => $response['canonical_url'] ?? null,
            'payload_checksum'   => $response['payload_checksum'] ?? null,
            'completed_at'       => $now,
            'updated_at'         => $now,
        ]);

        AuditLogger::record('publishing.accepted', [
            'deployment_id'    => $deploymentId,
            'public_content_id'=> $response['public_content_id'] ?? null,
            'operation'        => $deployment['operation'],
            'resulting_status' => $status,
        ]);

        if ($status === 'published' && ($deployment['content_item_id'] ?? null)) {
            $this->tryTrackBlogStateTransition((int) $deployment['content_item_id']);
        }
    }

    /**
     * Best-effort automation bookkeeping: items created and progressed by
     * the roadmap-automation pipeline live in BlogStateMachine's vocabulary
     * end-to-end (idea -> ... -> publishing -> published). Manually authored
     * content that has never entered that pipeline uses a different
     * workflow_status vocabulary (App\Libraries\ContentWorkflowService) and
     * must not be forced through BlogStateMachine's adjacency rules here —
     * any mismatch is swallowed so it never blocks a publish that already
     * succeeded on the public site.
     */
    private function tryTrackBlogStateTransition(int $contentItemId): void
    {
        try {
            $item = $this->db->table('reach_content_items')
                ->where('id', $contentItemId)->where('content_type', 'blog')->get()->getRowArray();
            if (! $item) {
                return;
            }
            $from = strtolower((string) ($item['workflow_status'] ?? ''));
            if ($from !== \App\Libraries\Blog\BlogStateMachine::PUBLISHING) {
                return;
            }
            (new \App\Libraries\Blog\BlogStateMachine())->transition($contentItemId, \App\Libraries\Blog\BlogStateMachine::PUBLISHED);
        } catch (\Throwable) {
            // Non-fatal bookkeeping only.
        }
    }

    private function onFailure(int $deploymentId, int $attemptId, string $errorCategory, string $message): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('reach_publication_attempts')->where('id', $attemptId)->update([
            'status'        => 'failed',
            'error_category'=> $errorCategory,
            'redacted_error'=> $message,
            'completed_at'  => $now,
        ]);

        $this->db->table('reach_publication_deployments')->where('id', $deploymentId)->update([
            'status'         => 'failed',
            'error_category' => $errorCategory,
            'redacted_error' => $message,
            'updated_at'     => $now,
        ]);

        AuditLogger::record('publishing.failed', [
            'deployment_id'  => $deploymentId,
            'error_category' => $errorCategory,
        ]);
    }

    private function updateDeploymentStatus(int $deploymentId, string $status, array $extra = []): void
    {
        $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)
            ->update(array_merge(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $extra));
    }

    /**
     * Build the envelope sent to the public receiver for one specific
     * sub-operation of a deployment. The idempotency key is suffixed per
     * sub-operation: create_draft, update_draft, publish and schedule are
     * distinct HTTP calls, and reusing one idempotency key across them would
     * make the public site's idempotency cache replay the first call's
     * response instead of executing the next one.
     */
    private function buildEnvelope(array $deployment, array $payload, string $checksum, string $subOperation): array
    {
        $versionNumber = $this->db->table('reach_content_versions')
            ->select('version_number')
            ->where('id', $deployment['content_version_id'])
            ->get()->getRowArray()['version_number'] ?? 1;

        return [
            'api_version'                  => 1,
            'operation'                    => $subOperation,
            'reach_content_id'             => (int) $deployment['content_item_id'],
            'reach_content_uuid'           => '',
            'reach_content_version_id'     => (int) $deployment['content_version_id'],
            'reach_content_version_number' => (int) $versionNumber,
            'content_type'                 => 'blog',
            'idempotency_key'              => $deployment['idempotency_key'] . ':' . $subOperation,
            'request_id'                   => $deployment['request_id'] ?? '',
            'timestamp'                    => time(),
            'nonce'                        => bin2hex(random_bytes(16)),
            'payload_checksum'             => $checksum,
            'publication_target'           => 'aicountly_com_blog',
            'payload'                      => $payload,
        ];
    }
}
