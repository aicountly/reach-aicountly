<?php

namespace App\Libraries\Publishing\Jobs;

use App\Libraries\AuditLogger;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;

/**
 * Phase 4 — Reconciliation service for publication deployments.
 *
 * Finds deployments that are claimed as published/verified but
 * whose public-site status does not match. Enqueues reconciliation
 * jobs for each discrepancy.
 */
class PublicationReconciliationService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Reconcile all published deployments that have not been recently verified.
     *
     * @param int $stalePeriodHours Re-verify if not checked in this many hours
     * @return array{checked: int, discrepancies: int}
     */
    public function reconcile(int $stalePeriodHours = 24): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$stalePeriodHours} hours"));

        $deployments = $this->db->table('reach_publication_deployments d')
            ->select('d.*')
            ->whereIn('d.status', ['published', 'verified'])
            ->where('d.public_content_id IS NOT NULL')
            ->where("d.updated_at < '{$cutoff}'")
            ->get()->getResultArray();

        $checked       = 0;
        $discrepancies = 0;
        $publisher     = PublicSitePublisherFactory::make();

        foreach ($deployments as $deployment) {
            $checked++;
            $response = $publisher->getStatus((int) $deployment['public_content_id']);

            if (!($response['success'] ?? false)) {
                $discrepancies++;
                AuditLogger::log('publishing.reconciliation_error', [
                    'deployment_id'    => $deployment['id'],
                    'public_content_id'=> $deployment['public_content_id'],
                ]);
                continue;
            }

            $remoteStatus = $response['public_status'] ?? '';
            $localStatus  = $deployment['status'];

            $expectedLocal = match ($remoteStatus) {
                'published' => ['published', 'verified'],
                'draft'     => ['rolled_back', 'unpublished'],
                default     => null,
            };

            if ($expectedLocal !== null && !in_array($localStatus, $expectedLocal, true)) {
                $discrepancies++;
                AuditLogger::log('publishing.reconciliation_discrepancy', [
                    'deployment_id' => $deployment['id'],
                    'local_status'  => $localStatus,
                    'remote_status' => $remoteStatus,
                ]);

                // Update local status to match
                $this->db->table('reach_publication_deployments')
                    ->where('id', $deployment['id'])
                    ->update([
                        'status'     => $remoteStatus === 'published' ? 'verified' : 'unpublished',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        }

        return ['checked' => $checked, 'discrepancies' => $discrepancies];
    }
}
