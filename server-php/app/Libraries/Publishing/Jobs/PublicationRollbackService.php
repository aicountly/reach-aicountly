<?php

namespace App\Libraries\Publishing\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;

/**
 * Phase 4 â€” Rollback published content on the public site.
 *
 * Unpublishes content and marks the deployment as rolled_back.
 * Human authorisation required.
 */
class PublicationRollbackService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Rollback a published deployment.
     */
    public function rollback(int $deploymentId, string $reason, ?int $authorisedBy = null): bool
    {
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)->get()->getRowArray();

        if (!$deployment) {
            throw new \RuntimeException("Deployment {$deploymentId} not found");
        }

        if (!in_array($deployment['status'], ['published', 'verified', 'accepted'], true)) {
            throw new \RuntimeException("Cannot rollback deployment with status: {$deployment['status']}");
        }

        $publicContentId = (int) $deployment['public_content_id'];

        if (!$publicContentId) {
            throw new \RuntimeException('Deployment has no public_content_id; cannot rollback');
        }

        $publisher = PublicSitePublisherFactory::make();
        $response  = $publisher->unpublish($publicContentId, $reason);

        if (!($response['success'] ?? false)) {
            AuditLogger::record('publishing.rollback_failed', [
                'deployment_id'  => $deploymentId,
                'error_category' => $response['error_category'] ?? 'unknown',
            ], $authorisedBy);
            return false;
        }

        $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)
            ->update([
                'status'     => 'rolled_back',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        AuditLogger::record('publishing.rolled_back', [
            'deployment_id'    => $deploymentId,
            'public_content_id'=> $publicContentId,
            'reason'           => $reason,
        ], $authorisedBy);

        return true;
    }
}

