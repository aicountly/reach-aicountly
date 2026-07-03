<?php

namespace App\Controllers\Api\V1\Portal;

use App\Controllers\BaseApiController;
use App\Libraries\MarketingBotService;
use App\Libraries\MarketingBotReporter;
use App\Models\ApprovalModel;
use App\Models\BotSettingModel;
use App\Models\MarketingBotReportModel;

/**
 * Portal-to-portal endpoints. Console calls these with X-Console-Token.
 * They are guarded by ConsoleTokenFilter (see Filters + Routes).
 */
class BotController extends BaseApiController
{
    public function health()
    {
        $m = new BotSettingModel();
        return $this->ok([
            'portal'     => 'reach.aicountly.org',
            'ok'         => true,
            'bot_mode'   => $m->currentMode(),
            'timestamp'  => gmdate('c'),
        ]);
    }

    public function getMode()
    {
        $m = new BotSettingModel();
        return $this->ok([
            'mode'                 => $m->currentMode(),
            'allowed_auto_actions' => $m->currentAllowedAutoActions(),
        ]);
    }

    public function setMode()
    {
        $body = $this->input();
        $mode = (string) ($body['mode'] ?? 'confirm');
        if (! in_array($mode, ['auto', 'confirm'], true)) {
            return $this->fail('mode must be auto or confirm.', 422);
        }
        $m       = new BotSettingModel();
        $allowed = is_array($body['allowed_auto_actions'] ?? null)
            ? array_values(array_intersect(MarketingBotService::ACTIONS, $body['allowed_auto_actions']))
            : $m->currentAllowedAutoActions();
        $row = $m->updateMode($mode, $allowed, null);
        $this->audit('bot.mode.remote', 'bot_settings', (int) ($row['id'] ?? 0), null, $row);
        return $this->ok([
            'mode'                 => $row['mode'],
            'allowed_auto_actions' => $row['allowed_auto_actions'] ?? [],
        ]);
    }

    /**
     * Console posts an approval decision that was made in its UI on our
     * behalf. Body: { report_id, decision (approved|rejected), note? }
     */
    public function approvalCallback()
    {
        $body     = $this->input();
        $reportId = (int) ($body['report_id'] ?? 0);
        $decision = (string) ($body['decision'] ?? '');
        if ($reportId <= 0 || ! in_array($decision, ['approved', 'rejected'], true)) {
            return $this->fail('report_id and decision (approved|rejected) required.', 422);
        }
        $reports = new MarketingBotReportModel();
        $rep     = $reports->find($reportId);
        if (! $rep) {
            return $this->fail('Report not found.', 404);
        }
        if ($decision === 'approved') {
            (new MarketingBotReporter())->markApproved($reportId, 0, 'queued');
        } else {
            (new MarketingBotReporter())->markRejected($reportId, 0, (string) ($body['note'] ?? null));
        }
        (new ApprovalModel())
            ->where('subject_type', 'bot')
            ->where('subject_id', $reportId)
            ->where('decision', 'pending')
            ->set([
                'decision'   => $decision,
                'decided_at' => date('Y-m-d H:i:s'),
                'note'       => (string) ($body['note'] ?? null),
            ])
            ->update();
        $this->audit('bot.approval.remote', 'bot_report', $reportId, null, ['decision' => $decision]);
        return $this->ok(['message' => 'Applied.']);
    }
}
