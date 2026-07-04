<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

class InitialReachSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // ---- Role: super_admin (only role Reach exposes) ----
        $roles = $this->db->table('reach_roles');
        $existingRole = $roles->where('slug', 'super_admin')->get()->getRow();
        if (! $existingRole) {
            $roles->insert([
                'slug'        => 'super_admin',
                'name'        => 'Superadmin',
                'description' => 'Full access to Reach marketing operations.',
                'permissions' => json_encode(['*']),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $roleId = (int) $this->db->insertID();
            CLI::write('Seeded role super_admin.', 'green');
        } else {
            $roleId = (int) $existingRole->id;
        }

        // ---- Superadmin user (from env; skip silently if incomplete) ----
        $email    = strtolower((string) env('SUPER_ADMIN_EMAIL', ''));
        $name     = (string) (env('SUPER_ADMIN_NAME') ?: 'Reach Superadmin');
        $password = (string) env('SUPER_ADMIN_PASSWORD', '');

        if ($email === '' || $password === '') {
            CLI::write(
                'SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD missing in .env — skipping user seed.',
                'yellow',
            );
        } else {
            $users = $this->db->table('reach_users');
            $existing = $users->where('email', $email)->get()->getRow();
            if ($existing) {
                $users->where('id', $existing->id)->update([
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'name'          => $name,
                    'role_id'       => $roleId,
                    'is_active'     => true,
                    'updated_at'    => $now,
                ]);
                CLI::write("Updated superadmin credentials: {$email}", 'green');
            } else {
                $users->insert([
                    'email'         => $email,
                    'name'          => $name,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'role_id'       => $roleId,
                    'is_active'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                CLI::write("Seeded superadmin: {$email}", 'green');
            }
        }

        // ---- Bot settings singleton (mode = env fallback / confirm) ----
        $botSettings = $this->db->table('reach_bot_settings');
        $existingBot = $botSettings->get()->getRow();
        if (! $existingBot) {
            $mode = (string) env('REACH_BOT_MODE', 'confirm');
            $mode = in_array($mode, ['auto', 'confirm'], true) ? $mode : 'confirm';
            $botSettings->insert([
                'mode'                 => $mode,
                'allowed_auto_actions' => json_encode([
                    // Safe internal-only actions that may run without approval when Auto mode is enabled.
                    'generate_campaign_ideas',
                    'generate_seo_brief',
                    'suggest_hashtags_keywords',
                    'generate_content_calendar',
                    'generate_analytics_summary',
                    'recommend_campaign_improvements',
                    'prepare_approval_package',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            CLI::write("Seeded reach_bot_settings (mode={$mode}).", 'green');
        }

        // ---- Default settings row so Settings UI has data to render ----
        $settings = $this->db->table('reach_settings');
        $defaults = [
            'brand.name'        => 'AICOUNTLY',
            'brand.tagline'     => 'AI for accountants',
            'blog.default_author' => $name ?: 'AICOUNTLY',
            'social.default_hashtags' => ['#AICOUNTLY', '#accountingAI'],
        ];
        foreach ($defaults as $key => $value) {
            $exists = $settings->where('key', $key)->countAllResults() > 0;
            if ($exists) {
                continue;
            }
            $settings->insert([
                'key'        => $key,
                'value_json' => json_encode($value),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        CLI::write('Seeded default reach_settings.', 'green');
    }
}
