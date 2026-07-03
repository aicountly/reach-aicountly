<?php

namespace App\Models;

use CodeIgniter\Model;

class BotSettingModel extends Model
{
    protected $table         = 'reach_bot_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = ['mode', 'allowed_auto_actions', 'updated_by'];
    protected array $casts   = ['allowed_auto_actions' => 'json-array'];

    /**
     * DB row is authoritative; env is only a fallback if the row is somehow missing.
     */
    public function currentMode(): string
    {
        $row = $this->first();
        if ($row && isset($row['mode'])) {
            return (string) $row['mode'];
        }
        $env = (string) env('REACH_BOT_MODE', 'confirm');
        return in_array($env, ['auto', 'confirm'], true) ? $env : 'confirm';
    }

    public function currentAllowedAutoActions(): array
    {
        $row = $this->first();
        if (! $row) {
            return [];
        }
        $raw = $row['allowed_auto_actions'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($raw) ? $raw : [];
    }

    public function updateMode(string $mode, array $allowedAutoActions, ?int $userId = null): array
    {
        $mode = in_array($mode, ['auto', 'confirm'], true) ? $mode : 'confirm';
        $row  = $this->first();
        if ($row) {
            $this->update($row['id'], [
                'mode'                 => $mode,
                'allowed_auto_actions' => json_encode(array_values($allowedAutoActions)),
                'updated_by'           => $userId,
            ]);
            return $this->find($row['id']);
        }
        $this->insert([
            'mode'                 => $mode,
            'allowed_auto_actions' => json_encode(array_values($allowedAutoActions)),
            'updated_by'           => $userId,
        ]);
        return $this->find((int) $this->db->insertID());
    }
}
