<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\MarketingBotService;
use App\Models\BotSettingModel;

class BotSettingsController extends BaseApiController
{
    public function index()
    {
        $m = new BotSettingModel();
        return $this->ok([
            'mode'                 => $m->currentMode(),
            'allowed_auto_actions' => $m->currentAllowedAutoActions(),
            'available_actions'    => MarketingBotService::ACTIONS,
        ]);
    }

    public function update()
    {
        $body    = $this->input();
        $mode    = (string) ($body['mode'] ?? 'confirm');
        $allowed = array_values((array) ($body['allowed_auto_actions'] ?? []));
        $allowed = array_values(array_intersect(MarketingBotService::ACTIONS, $allowed));
        $m       = new BotSettingModel();
        $before  = ['mode' => $m->currentMode(), 'allowed_auto_actions' => $m->currentAllowedAutoActions()];
        $row     = $m->updateMode($mode, $allowed, $this->userId());
        $this->audit(
            'bot.mode_changed',
            'bot_settings',
            (int) ($row['id'] ?? 0),
            $before,
            ['mode' => $row['mode'], 'allowed_auto_actions' => $row['allowed_auto_actions'] ?? []],
        );
        return $this->ok([
            'mode'                 => $row['mode'],
            'allowed_auto_actions' => $row['allowed_auto_actions'] ?? [],
        ]);
    }
}
