<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Models\LeadModel;
use Config\Services;

/**
 * Wraps EngageClient::pushLead so that Engage retries can be scheduled
 * off the sync request path. Not auto-scheduled in Phase 0 — registered
 * so that later phases can enqueue retries deterministically.
 *
 * Payload: { "lead_id": <int> }
 */
class EngagePushRetryJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $leadId = (int) ($payload['lead_id'] ?? 0);
        if ($leadId <= 0) {
            throw new \InvalidArgumentException('EngagePushRetryJob requires payload.lead_id');
        }
        $lead = (new LeadModel())->find($leadId);
        if (! $lead) {
            return ['ok' => false, 'error' => 'Lead not found', 'lead_id' => $leadId];
        }
        $result = Services::engageClient()->pushLead($lead);
        return [
            'ok'      => ($result['status'] ?? '') === 'pushed',
            'lead_id' => $leadId,
            'status'  => $result['status'] ?? null,
            'attempt' => $result['attempt'] ?? null,
            'job_id'  => $ctx->jobId,
        ];
    }
}
