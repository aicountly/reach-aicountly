<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDistributionPermissions extends Migration
{
    private array $permissions = [
        'distribution.read', 'distribution.create', 'distribution.update',
        'distribution.segment', 'distribution.preview', 'distribution.test_send',
        'distribution.submit', 'distribution.review', 'distribution.approve',
        'distribution.schedule', 'distribution.dispatch', 'distribution.pause',
        'distribution.cancel', 'distribution.retry',
        'distribution.connections.read', 'distribution.connections.manage',
        'distribution.templates.read', 'distribution.templates.manage',
        'distribution.consent.read', 'distribution.consent.manage',
        'distribution.suppression.read', 'distribution.suppression.manage',
        'distribution.operations.read', 'distribution.audit.read',
        'sms.read', 'sms.create', 'sms.update', 'sms.send',
    ];

    public function up(): void
    {
        // Only insert if a permissions table exists (Phase 4 created it)
        $tables = $this->db->listTables();
        if (!in_array('reach_permissions', $tables, true)) {
            return;
        }

        foreach ($this->permissions as $slug) {
            [$group, $action] = array_pad(explode('.', $slug, 2), 2, '');
            $exists = $this->db->table('reach_permissions')->where('slug', $slug)->countAllResults();
            if ($exists === 0) {
                $this->db->table('reach_permissions')->insert([
                    'slug'        => $slug,
                    'group_name'  => $group,
                    'description' => ucwords(str_replace(['.', '_'], ' ', $slug)),
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $tables = $this->db->listTables();
        if (!in_array('reach_permissions', $tables, true)) {
            return;
        }
        foreach ($this->permissions as $slug) {
            $this->db->table('reach_permissions')->where('slug', $slug)->delete();
        }
    }
}
