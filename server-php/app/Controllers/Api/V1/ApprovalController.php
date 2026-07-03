<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalModel;
use Config\Services;

class ApprovalController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new ApprovalModel();
        foreach (['subject_type', 'decision'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $q->where($f, $v);
            }
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new ApprovalModel())->find($id);
        if (! $row) {
            return $this->fail('Approval not found.', 404);
        }
        return $this->ok($row);
    }

    public function decide(int $id)
    {
        $body     = $this->input();
        $decision = (string) ($body['decision'] ?? '');
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            return $this->fail('decision must be approved or rejected.', 422);
        }
        $m   = new ApprovalModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Approval not found.', 404);
        }
        $m->update($id, [
            'decision'   => $decision,
            'decided_by' => $this->userId(),
            'decided_at' => date('Y-m-d H:i:s'),
            'note'       => (string) ($body['note'] ?? null),
        ]);
        $this->audit('approval.decide', 'approval', $id, $row, ['decision' => $decision]);
        // Console fan-out for approval decisions.
        try {
            Services::consoleAudit()->event('reach.approval.decided', [
                'approval_id'  => $id,
                'subject_type' => $row['subject_type'],
                'subject_id'   => (int) $row['subject_id'],
                'decision'     => $decision,
                'decided_by'   => $this->userId(),
            ]);
            $m->update($id, ['console_synced_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            log_message('error', 'Approval console sync failed: ' . $e->getMessage());
        }
        return $this->ok($m->find($id));
    }
}
