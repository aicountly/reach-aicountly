<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Settings\ProviderConnectivityCheck;
use App\Libraries\Settings\ReachConfig;

/**
 * Settings → AI & Automation: AI keys, the vetting model per content type,
 * image model, automation volumes, report recipients and third-party
 * credentials. Secrets are write-only — reads say whether one is set and
 * where it comes from, never its value.
 */
class AutomationSettingsController extends BaseApiController
{
    public function index()
    {
        return $this->ok(ReachConfig::describe());
    }

    public function update()
    {
        $body    = $this->input();
        $changed = [];
        try {
            foreach ($body as $key => $value) {
                ReachConfig::set((string) $key, $value, $this->userId());
                $changed[] = (string) $key;
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        // A provider only becomes routable once the AI catalog records its
        // key; reach:schedule (every minute) notices the change and re-seeds.

        $this->audit('settings.automation_update', 'settings', null, null, ['keys' => $changed]);
        return $this->ok(ReachConfig::describe());
    }

    public function test()
    {
        $target = (string) ($this->input()['target'] ?? '');
        $result = (new ProviderConnectivityCheck())->check($target);
        $this->audit('settings.automation_test', 'settings', null, null, ['target' => $target, 'ok' => $result['ok']]);
        return $this->ok($result);
    }

    public function geminiModels()
    {
        return $this->ok((new ProviderConnectivityCheck())->geminiModels());
    }
}
