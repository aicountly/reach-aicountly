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
        $override = (bool) ($body['override'] ?? false);
        $reason   = isset($body['reason']) ? (string) $body['reason'] : null;
        $note     = isset($body['note'])   ? (string) $body['note']   : null;

        if (! in_array($decision, ['approved', 'rejected'], true)) {
            return $this->fail('decision must be approved or rejected.', 422);
        }
        $m   = new ApprovalModel();
        $row = $m->find($id);
        if (! $row) {
            return $this->fail('Approval not found.', 404);
        }

        // Only apply ApprovalPolicy on approvals; rejections require just approval.decide.
        if ($decision === 'approved') {
            $policy = Services::approvalPolicy();
            $result = $policy->canApprove($row, $this->user() ?? ['id' => $this->userId()], $override, $reason);
            if (! $result->allowed) {
                Services::auditLogger()->log(
                    userId: $this->userId(),
                    action: 'approval.policy_denied',
                    entityType: 'approval',
                    entityId: $id,
                    newValue: ['rule' => $result->rule, 'message' => $result->message],
                );
                return $this->fail($result->message ?? 'Approval denied by policy.', 403, [
                    'rule' => $result->rule,
                ]);
            }
        }

        $m->update($id, [
            'decision'   => $decision,
            'decided_by' => $this->userId(),
            'decided_at' => date('Y-m-d H:i:s'),
            'note'       => $note,
        ]);
        $auditPayload = ['decision' => $decision];
        if ($override) {
            $auditPayload['override'] = true;
            $auditPayload['reason']   = $reason;
        }
        $this->audit('approval.decided', 'approval', $id, $row, $auditPayload, $reason);
        if ($override) {
            $this->audit('approval.overridden', 'approval', $id, $row, $auditPayload, $reason);
        }

        try {
            Services::consoleAudit()->event('reach.approval.decided', [
                'approval_id'  => $id,
                'subject_type' => $row['subject_type'],
                'subject_id'   => (int) $row['subject_id'],
                'decision'     => $decision,
                'decided_by'   => $this->userId(),
                'override'     => $override,
            ]);
            $m->update($id, ['console_synced_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            log_message('error', 'Approval console sync failed: ' . $e->getMessage());
        }
        return $this->ok($m->find($id));
    }
}
