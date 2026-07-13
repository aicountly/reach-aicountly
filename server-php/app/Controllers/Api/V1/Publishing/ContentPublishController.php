<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Publishing\Jobs\PublicationDeploymentService;
use App\Libraries\Publishing\Jobs\PublicationRollbackService;

class ContentPublishController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function publish(int $contentItemId): \CodeIgniter\HTTP\ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $actor = $this->request->actor ?? null;

        $versionId     = $body['content_version_id'] ?? $this->getLatestVersionId($contentItemId);
        $connectionKey = $body['connection_key'] ?? 'aicountly_com';

        if (!$versionId) {
            return $this->error('No published version found for content', 422);
        }

        try {
            $service      = new PublicationDeploymentService();
            $deploymentId = $service->enqueuePublication(
                $contentItemId,
                $versionId,
                $connectionKey,
                'publish',
                null,
                $actor?->id
            );

            return $this->ok(['deployment_id' => $deploymentId, 'status' => 'queued']);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function schedule(int $contentItemId): \CodeIgniter\HTTP\ResponseInterface
    {
        $body  = $this->request->getJSON(true) ?? [];
        $actor = $this->request->actor ?? null;

        $scheduledAt   = $body['scheduled_at'] ?? null;
        $versionId     = $body['content_version_id'] ?? $this->getLatestVersionId($contentItemId);
        $connectionKey = $body['connection_key'] ?? 'aicountly_com';

        if (!$scheduledAt) {
            return $this->error('scheduled_at is required', 422);
        }

        if (!$versionId) {
            return $this->error('No version found for content', 422);
        }

        try {
            $service      = new PublicationDeploymentService();
            $deploymentId = $service->enqueuePublication(
                $contentItemId,
                $versionId,
                $connectionKey,
                'schedule',
                $scheduledAt,
                $actor?->id
            );

            return $this->ok(['deployment_id' => $deploymentId, 'status' => 'queued']);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function unpublish(int $contentItemId): \CodeIgniter\HTTP\ResponseInterface
    {
        $body   = $this->request->getJSON(true) ?? [];
        $actor  = $this->request->actor ?? null;
        $reason = $body['reason'] ?? 'Manual unpublish';

        // Find the active deployment
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('content_item_id', $contentItemId)
            ->whereIn('status', ['published', 'verified'])
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if (!$deployment) {
            return $this->error('No active published deployment found', 422);
        }

        try {
            $success = (new PublicationRollbackService())->rollback($deployment['id'], $reason, $actor?->id);
            return $this->ok(['unpublished' => $success]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    private function getLatestVersionId(int $contentItemId): ?int
    {
        $version = $this->db->table('reach_content_versions')
            ->where('content_item_id', $contentItemId)
            ->orderBy('version_number', 'DESC')
            ->limit(1)->get()->getRowArray();

        return $version ? (int) $version['id'] : null;
    }
}
