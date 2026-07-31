<?php

namespace App\Libraries\Publishing\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;

/**
 * Phase 4 - Rollback published content on the public site.
 *
 * A rollback is a REVERT, not a takedown: it calls the public receiver's
 * /restore endpoint (PublisherController::handleRestore on aicountly-com),
 * which swaps the live package pointer back to previous_version_key and
 * keeps status='published' - the article stays live, just showing its
 * prior version. This is deliberately distinct from
 * WorkBlockService::TYPE_UNPUBLISH_BLOG (which calls unpublish() and takes
 * the article down entirely). Calling unpublish() here would make rollback
 * and emergency-unpublish functionally identical, which is exactly the gap
 * this class previously had - restore() was defined on every publisher
 * implementation (including the real AicountlyPublicSitePublisher HTTP
 * client) and exercised only against the mock in unit tests, but nothing
 * in the actual pipeline ever called it.
 *
 * Marks the deployment 'rolled_back' (distinct from 'verified'/'published'
 * and from the genuine takedown status 'unpublished' - both are valid
 * values in reach_publication_deployments' status CHECK constraint,
 * alongside a distinct 'restore'/'unpublish'/'rollback' operation enum,
 * confirming the schema always intended these as separate concepts) so
 * UNPUBLISH_BLOG's own deployment lookup does not mistake a rolled-back
 * deployment for one still eligible for a fresh unpublish, and so a
 * subsequent republish creates a new deployment rather than reusing this
 * one. Human authorisation required.
 */
class PublicationRollbackService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Rollback a published deployment to its previous version, keeping the
     * article live.
     */
    public function rollback(int $deploymentId, string $reason, ?int $authorisedBy = null): bool
    {
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)->get()->getRowArray();

        if (!$deployment) {
            throw new \RuntimeException("Deployment {$deploymentId} not found");
        }

        // 'rolled_back' is included so a deployment can be rolled back again
        // (e.g. v3 -> v2, then later v2 -> v1) without minting a new row.
        if (!in_array($deployment['status'], ['published', 'verified', 'accepted', 'rolled_back'], true)) {
            throw new \RuntimeException("Cannot rollback deployment with status: {$deployment['status']}");
        }

        $publicContentId = (int) $deployment['public_content_id'];

        if (!$publicContentId) {
            throw new \RuntimeException('Deployment has no public_content_id; cannot rollback');
        }

        $publisher = PublicSitePublisherFactory::make();
        $response  = $publisher->restore($publicContentId, [
            'operation'  => 'restore',
            'reason'     => $reason,
            'request_id' => bin2hex(random_bytes(8)),
        ]);

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

    /**
     * Take a published deployment down entirely (genuine unpublish, not a
     * version revert). Distinct from rollback() above - see class docblock.
     */
    public function unpublish(int $deploymentId, string $reason, ?int $authorisedBy = null): bool
    {
        $deployment = $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)->get()->getRowArray();

        if (!$deployment) {
            throw new \RuntimeException("Deployment {$deploymentId} not found");
        }

        // 'rolled_back' is included because a rolled-back deployment is
        // still live on the public site (just showing a prior version) and
        // must remain eligible for a genuine takedown.
        if (!in_array($deployment['status'], ['published', 'verified', 'accepted', 'rolled_back'], true)) {
            throw new \RuntimeException("Cannot unpublish deployment with status: {$deployment['status']}");
        }

        $publicContentId = (int) $deployment['public_content_id'];

        if (!$publicContentId) {
            throw new \RuntimeException('Deployment has no public_content_id; cannot unpublish');
        }

        $publisher = PublicSitePublisherFactory::make();
        $response  = $publisher->unpublish($publicContentId, $reason);

        if (!($response['success'] ?? false)) {
            AuditLogger::record('publishing.unpublish_failed', [
                'deployment_id'  => $deploymentId,
                'error_category' => $response['error_category'] ?? 'unknown',
            ], $authorisedBy);
            return false;
        }

        $this->db->table('reach_publication_deployments')
            ->where('id', $deploymentId)
            ->update([
                'status'     => 'unpublished',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        AuditLogger::record('publishing.unpublished', [
            'deployment_id'    => $deploymentId,
            'public_content_id'=> $publicContentId,
            'reason'           => $reason,
        ], $authorisedBy);

        return true;
    }
}
