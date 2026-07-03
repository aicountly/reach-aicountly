<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalModel;
use App\Models\CampaignModel;

class CampaignController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new CampaignModel();
        foreach (['campaign_type', 'status', 'approval_status'] as $filter) {
            $val = trim((string) $this->request->getGet($filter));
            if ($val !== '') {
                $q->where($filter, $val);
            }
        }
        $search = trim((string) $this->request->getGet('search'));
        if ($search !== '') {
            $q->like('name', $search);
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('updated_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new CampaignModel())->find($id);
        if (! $row) {
            return $this->fail('Campaign not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $m    = new CampaignModel();
        $row  = $this->normalize($body);
        $row['created_by'] = $this->userId();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        $this->audit('campaign.create', 'campaign', $id, null, $row);
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m   = new CampaignModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Campaign not found.', 404);
        }
        $update = $this->normalize($this->input(), partial: true);
        $m->update($id, $update);
        $this->audit('campaign.update', 'campaign', $id, $row, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m   = new CampaignModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Campaign not found.', 404);
        }
        $m->update($id, ['status' => 'archived']);
        $this->audit('campaign.archive', 'campaign', $id, $row, ['status' => 'archived']);
        return $this->ok(['message' => 'Archived.']);
    }

    public function approve(int $id)
    {
        $m   = new CampaignModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Campaign not found.', 404);
        }
        $m->update($id, [
            'approval_status' => 'approved',
            'status'          => 'approved',
            'approved_by'     => $this->userId(),
            'approved_at'     => date('Y-m-d H:i:s'),
        ]);
        (new ApprovalModel())->insert([
            'subject_type' => 'campaign',
            'subject_id'   => $id,
            'summary'      => 'Campaign approved',
            'requested_by' => $row['created_by'],
            'decision'     => 'approved',
            'decided_by'   => $this->userId(),
            'decided_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->audit('campaign.approve', 'campaign', $id, $row, ['status' => 'approved']);
        return $this->ok($m->find($id));
    }

    public function setStatus(int $id)
    {
        $m   = new CampaignModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Campaign not found.', 404);
        }
        $status = (string) ($this->input()['status'] ?? '');
        $m->update($id, ['status' => $status]);
        $this->audit('campaign.status', 'campaign', $id, ['status' => $row['status']], ['status' => $status]);
        return $this->ok($m->find($id));
    }

    private function normalize(array $body, bool $partial = false): array
    {
        $allowed = [
            'name', 'campaign_type', 'objective', 'target_audience', 'products_promoted',
            'budget_amount', 'currency', 'start_date', 'end_date', 'status', 'channels',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'landing_page_url', 'creative_copy', 'analytics_summary', 'leads_generated', 'bot_generated',
        ];
        $out = array_intersect_key($body, array_flip($allowed));
        foreach (['target_audience', 'products_promoted', 'channels', 'analytics_summary'] as $jsonField) {
            if (isset($out[$jsonField]) && is_array($out[$jsonField])) {
                $out[$jsonField] = json_encode($out[$jsonField]);
            }
        }
        if (! $partial) {
            $out['name']          ??= 'Untitled campaign';
            $out['campaign_type'] ??= 'multi';
            $out['status']        ??= 'draft';
        }
        return $out;
    }
}
