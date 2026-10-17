<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Libraries\Settings\ReachConfig;
use App\Models\SettingModel;
use Config\SensitiveSettings;

class SettingsController extends BaseApiController
{
    private const MASK = '••••••••';

    public function index()
    {
        return $this->ok($this->maskAll($this->generalSettings()));
    }

    public function update()
    {
        $body    = $this->input();
        $m       = new SettingModel();
        $managed = ReachConfig::registry();
        $policy  = config(SensitiveSettings::class);
        $keys    = [];
        foreach ($body as $key => $val) {
            $key = (string) $key;
            if (isset($managed[$key])) {
                return $this->fail("{$key} is managed on Settings → AI & Automation.", 422);
            }
            // The UI receives sensitive values masked. Saving the mask back
            // would overwrite the real secret, so an unchanged masked value
            // is skipped rather than stored.
            if ($val === self::MASK && $policy->isSensitive($key)) {
                continue;
            }
            $m->setSetting($key, $val, $this->userId());
            $keys[] = $key;
        }
        // Audit only carries the key names — never the raw values (which
        // may contain secrets). AuditLogger will also redact defensively.
        $this->audit('settings.update', 'settings', null, null, ['keys' => $keys]);
        // Extra channel-specific slug so integration audits can be alerted on
        // separately from generic settings edits.
        $this->audit('integration.setting_changed', 'settings', null, null, ['keys' => $keys]);
        return $this->ok($this->maskAll($this->generalSettings()));
    }

    /**
     * Every stored setting except the ones ReachConfig owns: those are edited
     * on their own screen and their secrets are stored encrypted, so showing
     * them here would only expose ciphertext.
     *
     * @return array<string, mixed>
     */
    private function generalSettings(): array
    {
        return array_diff_key((new SettingModel())->all(), ReachConfig::registry());
    }

    /**
     * Replace the value of every key tagged as sensitive with a fixed mask.
     * SettingModel::all() returns a flat key => value map.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function maskAll(array $settings): array
    {
        $policy = config(SensitiveSettings::class);
        foreach ($settings as $key => $value) {
            if ($value !== null && $value !== '' && $policy->isSensitive((string) $key)) {
                $settings[$key] = self::MASK;
            }
        }
        return $settings;
    }
}
