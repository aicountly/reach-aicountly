<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\NotificationService;
use App\Libraries\Refresh\RefreshEvidenceService;
use App\Libraries\Refresh\RefreshPolicyService;
use App\Libraries\Refresh\RefreshRecommendationService;
use App\Models\Intelligence\ContentIdentityModel;

/**
 * Detects content items in `published` status that haven't been refreshed
 * within the refresh window and transitions them to `refresh_due`.
 *
 * Phase 9 extension: for each identity with an active policy, the job
 * also generates a refresh evidence snapshot and recommendation via
 * RefreshRecommendationService.
 *
 * Payload: { tenant_id?: int }
 */
class ContentRefreshDetectionJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $db       = \Config\Database::connect();
        $notifSvc = new NotificationService();

        // ── Legacy: stale content item detection ─────────────────────────────
        $stale = $db->table('reach_content_items')
            ->where('workflow_status', 'published')
            ->where('published_at <', date('Y-m-d H:i:s', strtotime('-90 days')))
            ->where('deleted_at IS NULL')
            ->select('id, title, created_by')
            ->get()
            ->getResultArray();

        $transitioned = 0;
        foreach ($stale as $item) {
            $db->table('reach_content_items')
               ->where('id', $item['id'])
               ->update(['workflow_status' => 'refresh_due', 'updated_at' => date('Y-m-d H:i:s')]);

            if ($item['created_by']) {
                $notifSvc->dispatch(
                    (int) $item['created_by'],
                    NotificationService::TYPE_REFRESH_DUE,
                    "Content \"{$item['title']}\" is due for a refresh.",
                    ['entity_type' => 'content_item', 'entity_id' => $item['id'], 'action_url' => "/content/{$item['id']}"],
                    null
                );
            }
            $transitioned++;
        }

        // ── Phase 9: evidence-based recommendation generation ─────────────────
        $recommendationsGenerated = 0;
        if (! empty($payload['tenant_id'])) {
            $tenantId = (int) $payload['tenant_id'];
            try {
                $recommendationsGenerated = $this->runRefreshRecommendations($tenantId, date('Y-m-d'));
            } catch (\Throwable $e) {
                log_message('error', 'ContentRefreshDetectionJob P9 extension failed: ' . $e->getMessage());
            }
        }

        return [
            'ok'                      => true,
            'refresh_due'             => $transitioned,
            'recommendations_generated' => $recommendationsGenerated,
        ];
    }

    private function runRefreshRecommendations(int $tenantId, string $today): int
    {
        $policyService  = new RefreshPolicyService(
            new \App\Models\Refresh\RefreshPolicyModel(),
            new \App\Models\Refresh\RefreshPolicyVersionModel(),
            new \App\Libraries\AuditLogger(),
        );
        $evidenceService = new RefreshEvidenceService(
            new \App\Libraries\Intelligence\IntelligenceEvidenceService(
                new \App\Models\Intelligence\ContentIdentityModel(),
                new \App\Models\Intelligence\SearchMetricFactModel(),
                new \App\Models\Intelligence\ContentMetricFactModel(),
                new \App\Models\Intelligence\SitemapSnapshotModel(),
                new \App\Models\Intelligence\IndexNowSubmissionModel(),
                new \App\Models\Intelligence\AiVisibilityObservationModel(),
                new \App\Models\Intelligence\AttributionConversionLinkModel(),
            ),
            new \App\Models\Refresh\RefreshEvidenceSnapshotModel(),
            new \App\Libraries\AuditLogger(),
        );
        $recService = new RefreshRecommendationService(
            new \App\Models\Refresh\RefreshRecommendationModel(),
            new \App\Models\Refresh\RefreshScoreComponentModel(),
            new \App\Models\Refresh\RefreshEvidenceSnapshotModel(),
            new \App\Models\Refresh\RefreshPolicyVersionModel(),
            new \App\Libraries\AuditLogger(),
        );

        $identityModel = new ContentIdentityModel();
        $activePolicies = $policyService->getActiveForTenant($tenantId);
        $count = 0;

        foreach ($activePolicies as $policy) {
            $latestVersion = $policyService->getLatestApprovedVersion((int) $policy['id']);
            if (! $latestVersion) continue;

            $cutoffDate = date('Y-m-d', strtotime("-{$latestVersion['min_publication_age_days']} days"));
            $identities = $identityModel->getPublishedBeforeForTenant($tenantId, $cutoffDate, $policy['content_type']);

            foreach ($identities as $identity) {
                try {
                    $snapshot = $evidenceService->getOrCreateSnapshot(
                        $tenantId,
                        (int) $identity['id'],
                        (int) $latestVersion['id'],
                        $today,
                        (int) $latestVersion['comparison_window_days'],
                    );
                    $recommendation = $recService->evaluate($tenantId, (int) $snapshot['id']);
                    if ($recommendation) $count++;
                } catch (\Throwable $e) {
                    log_message('warning', "Refresh recommendation failed for identity {$identity['id']}: " . $e->getMessage());
                }
            }
        }

        return $count;
    }
}
