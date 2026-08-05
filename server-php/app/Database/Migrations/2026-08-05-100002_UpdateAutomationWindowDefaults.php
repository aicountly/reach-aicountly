<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * API blog route runs 21:00–09:00 IST every 2 hours (superadmin decision,
 * 2026-08-05). Replaces the earlier 19:00-start window and records the
 * cadence so the settings UI can display the cron truthfully.
 */
class UpdateAutomationWindowDefaults extends Migration
{
    private const WINDOW = [
        'timezone' => 'Asia/Kolkata',
        'windows'  => [
            ['start' => '00:00', 'end' => '08:59'],
            ['start' => '21:00', 'end' => '23:59'],
        ],
        'cadence_minutes' => 120,
    ];

    public function up(): void
    {
        $this->applyWindow(self::WINDOW);
    }

    public function down(): void
    {
        $this->applyWindow([
            'timezone' => 'Asia/Kolkata',
            'windows'  => [
                ['start' => '00:00', 'end' => '08:59'],
                ['start' => '19:00', 'end' => '23:59'],
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $window
     */
    private function applyWindow(array $window): void
    {
        $rows = $this->db->table('reach_blog_portfolio_config')->select('id, settings_json')->get()->getResultArray();

        foreach ($rows as $row) {
            $settings = is_string($row['settings_json'] ?? null)
                ? (json_decode($row['settings_json'], true) ?: [])
                : (array) ($row['settings_json'] ?? []);
            $settings['automation_window'] = $window;

            $this->db->table('reach_blog_portfolio_config')->where('id', $row['id'])->update([
                'settings_json' => json_encode($settings, JSON_UNESCAPED_SLASHES),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
