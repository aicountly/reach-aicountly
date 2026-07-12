<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\SettingModel;
use Config\SensitiveSettings;

class SettingsController extends BaseApiController
{
    private const MASK = '••••••••';

    public function index()
    {
        return $this->ok($this->maskAll((new SettingModel())->all()));
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
        // Audit only carries the key names — never the raw values (which
        // may contain secrets). AuditLogger will also redact defensively.
        $this->audit('settings.update', 'settings', null, null, ['keys' => $keys]);
        // Extra channel-specific slug so integration audits can be alerted on
        // separately from generic settings edits.
        $this->audit('integration.setting_changed', 'settings', null, null, ['keys' => $keys]);
        return $this->ok($this->maskAll($m->all()));
    }

    /**
     * Replace values for keys tagged as sensitive with a fixed mask. Rows
     * are otherwise unchanged so the UI can still render metadata (updated_at
     * etc.). If a row has no `value` column we simply pass it through.
     */
    private function maskAll(array $rows): array
    {
        $policy = config(SensitiveSettings::class);
        foreach ($rows as &$row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? $row['name'] ?? '');
            if ($key === '' || ! $policy->isSensitive($key)) {
                continue;
            }
            if (array_key_exists('value', $row) && $row['value'] !== null && $row['value'] !== '') {
                $row['value']  = self::MASK;
                $row['masked'] = true;
            }
        }
        return $rows;
    }
}
