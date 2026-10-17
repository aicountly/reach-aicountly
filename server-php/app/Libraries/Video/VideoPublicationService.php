<?php

declare(strict_types=1);

namespace App\Libraries\Video;

/**
 * Phase 6 CP8 — publication profiles for the legacy, manually driven video
 * projects.
 *
 * Uploading to YouTube is done only by the automated pipeline
 * (App\Libraries\Video\Automation\VideoPipelineRunner: resumable upload of the
 * Shotstack render through the connected channel). The old manual publish
 * path called a provider with the wrong signature and wrote columns that do
 * not exist; it is gone, and publish() says so instead of pretending.
 */
class VideoPublicationService
{
    public const MANUAL_PUBLISH_UNAVAILABLE = 'Manual YouTube publishing is not available. Approved videos are rendered and uploaded by the automated pipeline (Reach → Video → Pipeline).';

    public function __construct(
        private readonly VideoPublicationRepository $repo,
        private readonly VideoProjectRepository     $projectRepo,
    ) {
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

        $privacy = in_array($metadata['yt_privacy'] ?? '', ['public', 'unlisted', 'private'], true) ? $metadata['yt_privacy'] : 'private';

        return $this->repo->createProfile([
            'project_id'     => $projectId,
            'tenant_id'      => $tenantId,
            'platform'       => 'youtube',
            'title'          => mb_substr((string) ($metadata['yt_title'] ?? ''), 0, 100),
            'description'    => (string) ($metadata['yt_description'] ?? ''),
            'tags'           => array_values((array) ($metadata['yt_tags'] ?? [])),
            'category_id'    => (string) ($metadata['yt_category'] ?? ''),
            'privacy_status' => $privacy,
            'created_by'     => $actorId,
        ]);
    }

    /**
     * @throws \LogicException always — see MANUAL_PUBLISH_UNAVAILABLE
     */
    public function publish(array $project, array $renderJob, array $profile, int $connectionId, ?int $actorId): array
    {
        throw new \LogicException(self::MANUAL_PUBLISH_UNAVAILABLE);
    }

    /**
     * List publications for a tenant (across all projects).
     */
    public function listPublications(int $tenantId, int $page = 1, int $perPage = 25): array
    {
        return $this->repo->listDeployments($tenantId, $page, $perPage);
    }
}
