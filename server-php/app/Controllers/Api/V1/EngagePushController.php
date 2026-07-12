<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\CampaignModel;
use App\Models\LeadModel;
use Config\Services;

class EngagePushController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new LeadModel();
        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '') {
            $q->where('engage_push_status', $status);
        }
        $total = $q->countAllResults(false);
        $leads = $q->orderBy('last_push_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $leads, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function push(int $leadId)
    {
        $lead = (new LeadModel())->find($leadId);
        if (! $lead) {
            return $this->fail('Lead not found.', 404);
        }
        $result = Services::engageClient()->pushLead($this->enrich($lead));
        $this->audit('engage_push.push', 'lead', $leadId, null, ['status' => $result['status']]);
        return $this->ok([
            'lead_id'          => $leadId,
            'engage_status'    => $result['status'],
            'engage_lead_code' => $result['engage_lead_code'] ?? null,
            'attempt'          => $result['attempt'] ?? null,
        ]);
    }

    public function retry(int $leadId)
    {
        // Same as push but marks the intent explicitly.
        return $this->push($leadId);
    }

    private function enrich(array $lead): array
    {
        if (! empty($lead['campaign_id'])) {
            $c = (new CampaignModel())->find((int) $lead['campaign_id']);
            if ($c) {
                $lead['campaign_code'] = 'REACH-' . $c['id'];
                $lead['campaign_name'] = $c['name'];
                $lead['campaign_kind'] = $c['campaign_type'];
            }
        }
        return $lead;
    }
}
