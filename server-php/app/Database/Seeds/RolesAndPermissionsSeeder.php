<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use Config\Permissions;

/**
 * Phase 0 — seeds the six canonical Reach roles with distinct permission arrays.
 *
 *   super_admin       — wildcard, retained for backward compatibility.
 *   reach_admin       — everything except super_admin-only ops (bulk delete, etc.).
 *   marketing_manager — full content + approval for content it did not author.
 *   content_reviewer  — approvals + view content, no publish/dispatch.
 *   analyst           — read-only + analytics + audit view.
 *   viewer            — dashboard + read-only view of content.
 *
 * Idempotent: existing rows are updated in place so re-running the seeder is safe.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $blogAll     = Permissions::groups()['blog'];
        $campaignAll = Permissions::groups()['campaign'];
        $socialAll   = Permissions::groups()['social'];
        $emailAll    = Permissions::groups()['email'];
        $whatsappAll = Permissions::groups()['whatsapp'];
        $leadAll     = Permissions::groups()['lead'];
        $approvalAll = Permissions::groups()['approval'];
        $botAll      = Permissions::groups()['bot'];
        $jobAll      = Permissions::groups()['job'];
        $integrationAll = Permissions::groups()['integration'];

        $roles = [
            [
                'slug' => 'super_admin',
                'name' => 'Superadmin',
                'description' => 'Full access to all Reach operations.',
                'permissions' => ['*'],
            ],
            [
                'slug' => 'reach_admin',
                'name' => 'Reach Admin',
                'description' => 'Full marketing operations; excludes destructive super_admin-only actions.',
                'permissions' => array_values(array_unique(array_merge(
                    [Permissions::DASHBOARD_VIEW, Permissions::ANALYTICS_VIEW, Permissions::AUDIT_VIEW],
                    [Permissions::SETTINGS_VIEW, Permissions::SETTINGS_MANAGE],
                    $blogAll, $campaignAll, $socialAll, $emailAll, $whatsappAll,
                    $leadAll, $approvalAll, $botAll, $jobAll, $integrationAll,
                ))),
            ],
            [
                'slug' => 'marketing_manager',
                'name' => 'Marketing Manager',
                'description' => 'Create, edit, submit, and (with policy) approve marketing content.',
                'permissions' => array_values(array_unique(array_merge(
                    [Permissions::DASHBOARD_VIEW, Permissions::ANALYTICS_VIEW],
                    $blogAll, $campaignAll, $socialAll, $emailAll, $whatsappAll,
                    [Permissions::LEAD_VIEW, Permissions::LEAD_MANAGE],
                    [Permissions::APPROVAL_VIEW, Permissions::APPROVAL_DECIDE],
                    [Permissions::BOT_VIEW, Permissions::BOT_DISPATCH],
                    [Permissions::JOB_VIEW],
                ))),
            ],
            [
                'slug' => 'content_reviewer',
                'name' => 'Content Reviewer',
                'description' => 'Reviews and approves/rejects content; cannot publish or dispatch.',
                'permissions' => [
                    Permissions::DASHBOARD_VIEW,
                    Permissions::BLOG_VIEW, Permissions::BLOG_APPROVE,
                    Permissions::CAMPAIGN_VIEW, Permissions::CAMPAIGN_APPROVE,
                    Permissions::SOCIAL_VIEW, Permissions::SOCIAL_APPROVE,
                    Permissions::EMAIL_VIEW, Permissions::EMAIL_APPROVE,
                    Permissions::WHATSAPP_VIEW, Permissions::WHATSAPP_APPROVE,
                    Permissions::APPROVAL_VIEW, Permissions::APPROVAL_DECIDE,
                    Permissions::LEAD_VIEW,
                    Permissions::BOT_VIEW,
                ],
            ],
            [
                'slug' => 'analyst',
                'name' => 'Analyst',
                'description' => 'Read-only analytics + audit visibility.',
                'permissions' => [
                    Permissions::DASHBOARD_VIEW,
                    Permissions::ANALYTICS_VIEW,
                    Permissions::AUDIT_VIEW,
                    Permissions::BLOG_VIEW, Permissions::CAMPAIGN_VIEW,
                    Permissions::SOCIAL_VIEW, Permissions::EMAIL_VIEW,
                    Permissions::WHATSAPP_VIEW, Permissions::LEAD_VIEW,
                ],
            ],
            [
                'slug' => 'viewer',
                'name' => 'Viewer',
                'description' => 'Dashboard + read-only view of published content.',
                'permissions' => [
                    Permissions::DASHBOARD_VIEW,
                    Permissions::BLOG_VIEW, Permissions::CAMPAIGN_VIEW,
                    Permissions::SOCIAL_VIEW, Permissions::EMAIL_VIEW,
                    Permissions::WHATSAPP_VIEW,
                ],
            ],
        ];

        $tbl = $this->db->table('reach_roles');
        foreach ($roles as $role) {
            $existing = $tbl->where('slug', $role['slug'])->get()->getRowArray();
            if ($existing) {
                $tbl->where('id', $existing['id'])->update([
                    'name'        => $role['name'],
                    'description' => $role['description'],
                    'permissions' => json_encode($role['permissions']),
                    'updated_at'  => $now,
                ]);
                CLI::write("Updated role {$role['slug']} (" . count($role['permissions']) . ' perms).', 'green');
            } else {
                $tbl->insert([
                    'slug'        => $role['slug'],
                    'name'        => $role['name'],
                    'description' => $role['description'],
                    'permissions' => json_encode($role['permissions']),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                CLI::write("Seeded role {$role['slug']} (" . count($role['permissions']) . ' perms).', 'green');
            }
        }

        // Ensure the canonical "system" bot user exists as a non-login actor for FK usage.
        $usersTbl = $this->db->table('reach_users');
        $roleId = (int) ($tbl->where('slug', 'super_admin')->get()->getRowArray()['id'] ?? 0);
        $sysEmail = 'system-bot@reach.local';
        if (! $usersTbl->where('email', $sysEmail)->get()->getRow() && $roleId > 0) {
            $usersTbl->insert([
                'email'             => $sysEmail,
                'name'              => 'Reach System Bot',
                'password_hash'     => '',
                'role_id'           => $roleId,
                'is_active'         => false,
                'is_login_disabled' => true,
                'actor_type'        => 'system',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            CLI::write('Seeded system bot user (non-login).', 'green');
        }
    }
}
