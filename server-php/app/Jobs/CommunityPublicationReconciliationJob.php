<?php

namespace App\Jobs;

use App\Libraries\Community\CommunityPublicationReconciliationService;
use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;

/**
 * Phase 2 — Community Publication Reconciliation Job.
 *
 * Job type key: reach.community_publication_reconciliation
 *
 * Payload: {} (no parameters — always reconciles the full published set)
 *
 * Batch-verifies every published official answer against the public site's
 * receiver via CommunityPublicationReconciliationService. Intended to run on
 * a schedule (see community schedule tick) independent of the per-answer
 * manual /verify endpoint.
 */
class CommunityPublicationReconciliationJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $summary = (new CommunityPublicationReconciliationService())->reconcileAll();

        return array_merge(['ok' => true], $summary);
    }
}
