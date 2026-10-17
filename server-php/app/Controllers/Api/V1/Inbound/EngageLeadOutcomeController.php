<?php

namespace App\Controllers\Api\V1\Inbound;

use App\Controllers\BaseApiController;
use App\Libraries\Leads\LeadOutcomeIngestService;
use Config\Services;

/**
 * POST v1/inbound/engage/lead-outcomes — Engage reports lead stage changes and
 * confirmed payments. Guarded by EngageTokenFilter (X-Engage-Token); outside the
 * jwt group. docs/integrations/ENGAGE_OUTCOMES.md
 *
 * 200 when every event was accepted, duplicate or rejected (rejections are
 * permanent: fix the event, do not resend it unchanged); 500 when any event
 * `failed` on our side — the whole batch may be resent, because accepted events
 * come back as `duplicate`. 422 when the body has no `events` list or more than
 * MAX_EVENTS of them.
 */
class EngageLeadOutcomeController extends BaseApiController
{
    public function store()
    {
        try {
            $body = $this->request->getJSON(true);
        } catch (\Throwable) {
            $body = null;
        }
        if (! is_array($body) || ! is_array($body['events'] ?? null) || ! array_is_list($body['events'])) {
            return $this->fail('Body must be JSON {"events":[...]}.', 422);
        }
        $events = $body['events'];
        if ($events === []) {
            return $this->fail('events is empty.', 422);
        }
        if (count($events) > LeadOutcomeIngestService::MAX_EVENTS) {
            return $this->fail('At most ' . LeadOutcomeIngestService::MAX_EVENTS . ' events per request.', 422);
        }

        $result = (new LeadOutcomeIngestService())->ingest($events);

        if ($result['accepted'] > 0 || $result['rejected'] > 0 || $result['failed'] > 0) {
            Services::auditLogger()->log(
                userId:       null,
                action:       'lead_outcome.ingest',
                entityType:   'lead_outcome',
                entityId:     null,
                newValue:     array_intersect_key($result, array_flip(['received', 'accepted', 'duplicate', 'rejected', 'failed'])),
                actorType:    'service',
                actorService: 'engage:outcomes',
                requestId:    $this->request->reachRequestId ?? null,
            );
        }

        if ($result['failed'] > 0) {
            return $this->fail('Some events could not be stored; resend the batch (stored events answer duplicate).', 500, $result);
        }

        return $this->ok($result);
    }
}
