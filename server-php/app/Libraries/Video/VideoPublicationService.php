<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Libraries\Video\Providers\VideoProviderFactory;
use App\Libraries\Video\Providers\YouTubePublisherInterface;
use App\Libraries\AuditLogger;

/**
 * Phase 6 CP8 — Video publication service.
 *
 * Reuses Phase 4 publication infrastructure:
 *   reach_publication_deployments  — YouTube upload job lifecycle
 *   reach_publication_attempts     — Upload attempt history
 *   reach_publication_verifications — Post-upload verification
 *   reach_publication_idempotency_records — Publish deduplication
 *
 * Security contract:
 * - NEVER auto-publishes. Requires an explicit publish() call with actor ID.
 * - Idempotency key prevents duplicate upload jobs.
 * - YouTube tokens are NEVER logged or returned in responses.
 * - All publication actions are audited.
 */
class VideoPublicationService
{
    private YouTubePublisherInterface $publisher;

    public function __construct(
        private readonly VideoPublicationRepository $repo,
        private readonly VideoProjectRepository     $projectRepo,
    ) {
        $this->publisher = VideoProviderFactory::makeYouTubePublisher();
    }

    /**
     * Create or fetch an existing publication profile for the project.
     */
    public function getOrCreateProfile(int $projectId, int $tenantId, array $metadata, ?int $actorId): array
    {
        $existing = $this->repo->findProfileByProject($projectId, 'youtube');
        if ($existing !== null) {
            return $existing;
        }

        return $this->repo->createProfile([
            'project_id'     => $projectId,
            'tenant_id'      => $tenantId,
            'platform'       => 'youtube',
            'title'          => $metadata['yt_title'] ?? '',
            'description'    => $metadata['yt_description'] ?? '',
            'tags'           => json_encode($metadata['yt_tags'] ?? []),
            'category'       => $metadata['yt_category'] ?? '',
            'privacy_status' => $metadata['yt_privacy'] ?? 'private',
            'created_by'     => $actorId,
        ]);
    }

    /**
     * Publish a rendered video to YouTube.
     *
     * Checks idempotency, creates a deployment record, calls the publisher,
     * and records the attempt.
     */
    public function publish(
        array  $project,
        array  $renderJob,
        array  $profile,
        int    $connectionId,
        ?int   $actorId,
    ): array {
        $idempotencyKey = 'yt-publish:' . $project['uuid'] . ':' . $renderJob['uuid'];
        $db = \Config\Database::connect();

        $existing = $db->table('reach_publication_idempotency_records')
            ->where('idempotency_key', $idempotencyKey)
            ->get()->getRowArray();

        if ($existing !== null) {
            return ['status' => 'already_published', 'record' => $existing];
        }

        // Record idempotency
        $db->table('reach_publication_idempotency_records')->insert([
            'idempotency_key' => $idempotencyKey,
            'resolved_at'     => date('Y-m-d H:i:s'),
        ]);

        // Create deployment record
        $deploymentId = $this->repo->createDeployment([
            'project_id'      => (int) $project['id'],
            'connection_id'   => $connectionId,
            'profile_id'      => (int) $profile['id'],
            'status'          => 'publishing',
            'created_by'      => $actorId,
        ]);

        AuditLogger::record(AuditLogger::VIDEO_PUBLISHED, [
            'project_uuid'  => $project['uuid'],
            'deployment_id' => $deploymentId,
        ], $actorId);

        try {
            $receipt = $this->publisher->upload(
                outputAssetPath: '[asset-path-placeholder]',
                profileId: (int) ($profile['id'] ?? 0),
                connectionId: $connectionId,
                metadata: $profile,
            );

            // Record successful attempt
            $db->table('reach_publication_attempts')->insert([
                'deployment_id'  => $deploymentId,
                'attempt_number' => 1,
                'status'         => 'succeeded',
                'remote_id'      => $receipt->videoId ?? null,
                'raw_receipt'    => json_encode($receipt),
            ]);

            // Update deployment status
            $db->table('reach_publication_deployments')
                ->where('id', $deploymentId)
                ->update(['status' => 'published', 'completed_at' => date('Y-m-d H:i:s')]);

            // Update project status
            $projectStatus = \App\Enums\VideoProjectStatus::from($project['status']);
            $this->projectRepo->transitionStatusEnum(
                (int) $project['id'],
                $projectStatus,
                \App\Enums\VideoProjectStatus::Published
            );

            return ['status' => 'published', 'receipt' => $receipt];
        } catch (\Throwable $e) {
            $db->table('reach_publication_deployments')
                ->where('id', $deploymentId)
                ->update(['status' => 'failed']);

            $db->table('reach_publication_attempts')->insert([
                'deployment_id'  => $deploymentId,
                'attempt_number' => 1,
                'status'         => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * List publications for a tenant (across all projects).
     */
    public function listPublications(int $tenantId, int $page = 1, int $perPage = 25): array
    {
        return $this->repo->listDeployments($tenantId, $page, $perPage);
    }
}
