<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SettingModel;

class SettingsController extends BaseApiController
{
    public function index()
    {
        return $this->ok((new SettingModel())->all());
    }

    public function update()
    {
        $body = $this->input();
        $m    = new SettingModel();
        $keys = [];
        foreach ($body as $key => $val) {
            $m->setSetting((string) $key, $val, $this->userId());
            $keys[] = (string) $key;
        }
        $this->audit('settings.update', 'settings', null, null, ['keys' => $keys]);
        return $this->ok($m->all());
    }
}
