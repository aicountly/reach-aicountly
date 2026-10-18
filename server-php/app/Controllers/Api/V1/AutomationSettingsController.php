<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Settings\LiveIntegrationCheck;
use App\Libraries\Settings\ProviderConnectivityCheck;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\VideoProviders;

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

    /**
     * "Run live checks": the same checks as `php spark reach:integrations-check`
     * (Shotstack key, YouTube token + channel, DataForSEO, Gemini, OpenAI, TTS)
     * against the live services. Body: {"only": ["youtube", …]} (optional).
     * The Shotstack test render is CLI-only — it waits minutes and may bill.
     */
    public function liveCheck()
    {
        $body = $this->input();
        if (! empty($body['render_test'])) {
            return $this->fail('The Shotstack test render is only available from the server: php spark reach:integrations-check --render-test', 422);
        }
        try {
            $targets = LiveIntegrationCheck::parseTargets($body['only'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        $report = (new LiveIntegrationCheck(VideoProviders::http(), timeoutCap: 15))->run($targets);

        $this->audit('settings.automation_live_check', 'settings', null, null, [
            'targets' => $targets,
            'ok'      => $report['ok'],
            'failed'  => array_values(array_map(static fn ($r) => $r['target'], array_filter($report['results'], static fn ($r) => ! $r['ok']))),
        ]);
        return $this->ok($report);
    }

    public function geminiModels()
    {
        return $this->ok((new ProviderConnectivityCheck())->geminiModels());
    }
}
