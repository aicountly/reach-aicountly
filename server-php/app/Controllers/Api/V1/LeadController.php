<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\CampaignModel;
use App\Models\LeadModel;
use Config\Services;

class LeadController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new LeadModel();
        foreach (['engage_push_status', 'source_kind', 'priority'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $q->where($f, $v);
            }
        }
        $search = trim((string) $this->request->getGet('search'));
        if ($search !== '') {
            $q->groupStart()
                ->like('name', $search)
                ->orLike('email', $search)
                ->orLike('mobile', $search)
                ->orLike('organization', $search)
              ->groupEnd();
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new LeadModel())->find($id);
        if (! $row) {
            return $this->fail('Lead not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $row  = $this->normalize($body);
        $row['created_by'] = $this->userId();
        $m = new LeadModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        $this->audit('lead.create', 'lead', $id, null, $row);
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m = new LeadModel();
        if (! $m->find($id)) {
            return $this->fail('Lead not found.', 404);
        }
        $update = $this->normalize($this->input(), partial: true);
        $m->update($id, $update);
        $this->audit('lead.update', 'lead', $id, null, $update);
        return $this->ok($m->find($id));
    }

    public function destroy(int $id)
    {
        $m = new LeadModel();
        if (! $m->find($id)) {
            return $this->fail('Lead not found.', 404);
        }
        $m->delete($id);
        $this->audit('lead.delete', 'lead', $id);
        return $this->ok(['message' => 'Deleted.']);
    }

    /**
     * Public capture endpoint for embedded forms / landing pages.
     * Guarded by PublicCaptureFilter (X-Public-Capture-Token).
     */
    public function publicCapture()
    {
        $body = $this->input();
        if (trim((string) ($body['name'] ?? '')) === '') {
            return $this->fail('name is required.', 422);
        }
        $row = $this->normalize($body);
        $row['raw_payload'] = json_encode($body, JSON_UNESCAPED_SLASHES);
        $m = new LeadModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();

        // Auto-push immediately (no approval needed for internal-only Engage push).
        $lead = $m->find($id);
        try {
            $result = Services::engageClient()->pushLead($this->enrichForEngage($lead));
            $this->audit('lead.public_capture', 'lead', $id, null, ['engage_status' => $result['status']]);
            return $this->ok(['lead_id' => $id, 'engage_push_status' => $result['status']], 201);
        } catch (\Throwable $e) {
            log_message('error', 'Public capture engage push failed: ' . $e->getMessage());
            return $this->ok(['lead_id' => $id, 'engage_push_status' => 'retry_scheduled'], 201);
        }
    }

    private function normalize(array $body, bool $partial = false): array
    {
        $out = array_intersect_key($body, array_flip([
            'name', 'email', 'mobile', 'whatsapp', 'organization',
            'source_kind', 'campaign_id', 'landing_page_id',
            'product_interest', 'priority', 'notes',
        ]));
        if (isset($out['email'])) {
            $out['email'] = strtolower(trim((string) $out['email']));
        }
        if (! $partial) {
            $out['name']     ??= '';
            $out['priority'] ??= 'normal';
        }
        return $out;
    }

    private function enrichForEngage(array $lead): array
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
