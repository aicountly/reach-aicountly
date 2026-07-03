<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\EmailCampaignModel;

class EmailCampaignController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new EmailCampaignModel();
        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '') {
            $q->where('status', $status);
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('updated_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new EmailCampaignModel())->find($id);
        if (! $row) {
            return $this->fail('Email campaign not found.', 404);
        }
        return $this->ok($row);
    }

    public function store()
    {
        $body = $this->input();
        $row  = $this->normalize($body);
        $row['created_by'] = $this->userId();
        $m = new EmailCampaignModel();
        $m->insert($row);
        $id = (int) $m->db->insertID();
        $this->audit('email.create', 'email_campaign', $id, null, $row);
        return $this->ok($m->find($id), 201);
    }

    public function update(int $id)
    {
        $m   = new EmailCampaignModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Email campaign not found.', 404);
        }
        $update = $this->normalize($this->input(), partial: true);
        $m->update($id, $update);
        $this->audit('email.update', 'email_campaign', $id, $row, $update);
        return $this->ok($m->find($id));
    }

    public function markSent(int $id)
    {
        $m   = new EmailCampaignModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Email campaign not found.', 404);
        }
        $stats = $this->input()['stats'] ?? [];
        $m->update($id, [
            'status'  => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
            'stats'   => is_array($stats) ? json_encode($stats) : null,
        ]);
        $this->audit('email.sent', 'email_campaign', $id, null, ['stats' => $stats]);
        return $this->ok($m->find($id));
    }

    private function normalize(array $body, bool $partial = false): array
    {
        $allowed = [
            'campaign_id', 'subject', 'from_name', 'from_email',
            'body_html', 'body_text', 'audience_filter', 'scheduled_at', 'status', 'stats',
        ];
        $out = array_intersect_key($body, array_flip($allowed));
        foreach (['audience_filter', 'stats'] as $jf) {
            if (isset($out[$jf]) && is_array($out[$jf])) {
                $out[$jf] = json_encode($out[$jf]);
            }
        }
        if (! $partial) {
            $out['status']  ??= 'draft';
            $out['subject'] ??= 'Untitled email';
        }
        return $out;
    }
}
