<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ApprovalModel;
use App\Models\MarketingBotQueueModel;
use App\Models\MarketingBotReportModel;
use Config\Services;

class MarketingBotController extends BaseApiController
{
    public function dispatch()
    {
        $body   = $this->input();
        $action = (string) ($body['action'] ?? '');
        $payload = (array) ($body['payload'] ?? []);
        if ($action === '') {
            return $this->fail('action is required.', 422);
        }
        try {
            $result = Services::marketingBot()->dispatch($action, $payload, $this->userId());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($result);
    }

    public function queue()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new MarketingBotQueueModel();
        $status = trim((string) $this->request->getGet('status'));
        if ($status !== '') {
            $q->where('status', $status);
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function queueItem(int $id)
    {
        $row = (new MarketingBotQueueModel())->find($id);
        if (! $row) {
            return $this->fail('Queue item not found.', 404);
        }
        $reports = (new MarketingBotReportModel())
            ->where('queue_id', $id)
            ->orderBy('created_at', 'DESC')
            ->findAll();
        return $this->ok(['queue' => $row, 'reports' => $reports]);
    }

    public function approveItem(int $queueId)
    {
        $reports = new MarketingBotReportModel();
        $rep     = $reports->where('queue_id', $queueId)->orderBy('created_at', 'DESC')->first();
        if (! $rep) {
            return $this->fail('No report for this queue item.', 404);
        }
        $result = Services::marketingBot()->executeApprovedPublishing((int) $rep['id'], (int) $this->userId());
        // Also close any pending reach_approvals row.
        (new ApprovalModel())
            ->where('subject_type', 'bot')
            ->where('subject_id', (int) $rep['id'])
            ->where('decision', 'pending')
            ->set([
                'decision'   => 'approved',
                'decided_by' => $this->userId(),
                'decided_at' => date('Y-m-d H:i:s'),
            ])
            ->update();
        $this->audit('bot.approve', 'bot_report', (int) $rep['id'], null, $result);
        return $this->ok($result);
    }

    public function rejectItem(int $queueId)
    {
        $reports = new MarketingBotReportModel();
        $rep     = $reports->where('queue_id', $queueId)->orderBy('created_at', 'DESC')->first();
        if (! $rep) {
            return $this->fail('No report for this queue item.', 404);
        }
        $note = (string) ($this->input()['note'] ?? '');
        Services::marketingBotReporter()->markRejected((int) $rep['id'], (int) $this->userId(), $note);
        (new ApprovalModel())
            ->where('subject_type', 'bot')
            ->where('subject_id', (int) $rep['id'])
            ->where('decision', 'pending')
            ->set([
                'decision'   => 'rejected',
                'decided_by' => $this->userId(),
                'decided_at' => date('Y-m-d H:i:s'),
                'note'       => $note,
            ])
            ->update();
        $this->audit('bot.reject', 'bot_report', (int) $rep['id'], null, ['note' => $note]);
        return $this->ok(['message' => 'Rejected.']);
    }
}
