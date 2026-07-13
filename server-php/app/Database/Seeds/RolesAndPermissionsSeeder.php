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

        $blogAll        = Permissions::groups()['blog'];
        $campaignAll    = Permissions::groups()['campaign'];
        $socialAll      = Permissions::groups()['social'];
        $emailAll       = Permissions::groups()['email'];
        $whatsappAll    = Permissions::groups()['whatsapp'];
        $leadAll        = Permissions::groups()['lead'];
        $approvalAll    = Permissions::groups()['approval'];
        $botAll         = Permissions::groups()['bot'];
        $jobAll         = Permissions::groups()['job'];
        $integrationAll = Permissions::groups()['integration'];

        // Phase 1 knowledge permission groups
        $knowledgeAll      = Permissions::groups()['knowledge'];
        $productAll        = Permissions::groups()['product'];
        $personaAll        = Permissions::groups()['persona'];
        $industryAll       = Permissions::groups()['industry'];
        $intentAll         = Permissions::groups()['intent'];
        $sourceAll         = Permissions::groups()['source'];
        $citationAll       = Permissions::groups()['citation'];
        $claimAll          = Permissions::groups()['claim'];
        $brandRulesAll     = Permissions::groups()['brand_rules'];
        $contentPolicyAll  = Permissions::groups()['content_policy'];

        // Phase 2 content studio permission groups
        $contentAll            = Permissions::groups()['content'];
        $contentVersionAll     = Permissions::groups()['content_version'];
        $contentCommentAll     = Permissions::groups()['content_comment'];
        $contentAssignmentAll  = Permissions::groups()['content_assignment'];
        $contentValidationAll  = Permissions::groups()['content_validation'];
        $dailyPackAll          = Permissions::groups()['daily_pack'];
        $contentScheduleAll    = Permissions::groups()['content_schedule'];
        $publicationTargetAll  = Permissions::groups()['publication_target'];

        // Phase 3 AI permission groups
        $aiAll             = Permissions::groups()['ai'];
        $aiProviderAll     = Permissions::groups()['ai_provider'];
        $aiModelAll        = Permissions::groups()['ai_model'];
        $aiRoutingAll      = Permissions::groups()['ai_routing'];
        $aiPromptAll       = Permissions::groups()['ai_prompt'];
        $aiGenerationAll   = Permissions::groups()['ai_generation'];
        $aiGroundingAll    = Permissions::groups()['ai_grounding'];
        $aiUsageAll        = Permissions::groups()['ai_usage'];
        $aiBudgetAll       = Permissions::groups()['ai_budget'];
        $aiValidationAll   = Permissions::groups()['ai_validation'];

        // AI view-only subsets for restricted roles
        $aiViewOnly = [
            Permissions::AI_VIEW,
            Permissions::AI_GENERATION_VIEW,
            Permissions::AI_VALIDATION_VIEW,
        ];

        $knowledgeViewOnly = [
            Permissions::KNOWLEDGE_VIEW,
            Permissions::PRODUCT_VIEW, Permissions::PERSONA_VIEW,
            Permissions::INDUSTRY_VIEW, Permissions::INTENT_VIEW,
            Permissions::SOURCE_VIEW, Permissions::CITATION_VIEW,
            Permissions::CLAIM_VIEW, Permissions::BRAND_RULES_VIEW,
            Permissions::CONTENT_POLICY_VIEW,
        ];

        $contentViewOnly = [
            Permissions::CONTENT_VIEW,
            Permissions::CONTENT_VERSION_VIEW,
            Permissions::CONTENT_COMMENT_VIEW,
            Permissions::CONTENT_ASSIGNMENT_VIEW,
            Permissions::CONTENT_VALIDATION_VIEW,
            Permissions::DAILY_PACK_VIEW,
            Permissions::CONTENT_SCHEDULE_VIEW,
            Permissions::PUBLICATION_TARGET_VIEW,
        ];

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
                    // Phase 1 knowledge
                    $knowledgeAll, $productAll, $personaAll, $industryAll, $intentAll,
                    $sourceAll, $citationAll, $claimAll, $brandRulesAll, $contentPolicyAll,
                    // Phase 2 content studio
                    $contentAll, $contentVersionAll, $contentCommentAll, $contentAssignmentAll,
                    $contentValidationAll, $dailyPackAll, $contentScheduleAll, $publicationTargetAll,
                    // Phase 3 AI — admins get full AI access
                    $aiAll, $aiProviderAll, $aiModelAll, $aiRoutingAll, $aiPromptAll,
                    $aiGenerationAll, $aiGroundingAll, $aiUsageAll, $aiBudgetAll, $aiValidationAll,
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
                    // Phase 1 knowledge — full create/edit/submit; approve own domain
                    $knowledgeAll, $productAll, $personaAll, $industryAll, $intentAll,
                    $sourceAll, $citationAll, $claimAll, $brandRulesAll, $contentPolicyAll,
                    // Phase 2 content studio — create, edit, submit, schedule, daily pack
                    [
                        Permissions::CONTENT_VIEW, Permissions::CONTENT_CREATE,
                        Permissions::CONTENT_EDIT, Permissions::CONTENT_SUBMIT,
                        Permissions::CONTENT_SCHEDULE_PERM,
                    ],
                    $contentVersionAll, $contentCommentAll, $contentAssignmentAll,
                    $contentValidationAll,
                    $dailyPackAll, $contentScheduleAll,
                    [Permissions::PUBLICATION_TARGET_VIEW],
                    // Phase 3 AI — managers can generate drafts; view usage/validations
                    [
                        Permissions::AI_VIEW, Permissions::AI_GENERATE, Permissions::AI_CANCEL,
                        Permissions::AI_BULK_GENERATE,
                        Permissions::AI_GENERATION_VIEW, Permissions::AI_GROUNDING_VIEW,
                        Permissions::AI_PROMPT_VIEW, Permissions::AI_PROMPT_APPROVE,
                        Permissions::AI_VALIDATION_VIEW, Permissions::AI_VALIDATION_WAIVE,
                        Permissions::AI_USAGE_VIEW, Permissions::AI_BUDGET_VIEW,
                    ],
                ))),
            ],
            [
                'slug' => 'content_reviewer',
                'name' => 'Content Reviewer',
                'description' => 'Reviews and approves/rejects content; cannot publish or dispatch.',
                'permissions' => array_values(array_unique(array_merge([
                    Permissions::DASHBOARD_VIEW,
                    Permissions::BLOG_VIEW, Permissions::BLOG_APPROVE,
                    Permissions::CAMPAIGN_VIEW, Permissions::CAMPAIGN_APPROVE,
                    Permissions::SOCIAL_VIEW, Permissions::SOCIAL_APPROVE,
                    Permissions::EMAIL_VIEW, Permissions::EMAIL_APPROVE,
                    Permissions::WHATSAPP_VIEW, Permissions::WHATSAPP_APPROVE,
                    Permissions::APPROVAL_VIEW, Permissions::APPROVAL_DECIDE,
                    Permissions::LEAD_VIEW,
                    Permissions::BOT_VIEW,
                    // Phase 1 knowledge — view + approve knowledge entities
                    Permissions::KNOWLEDGE_VIEW, Permissions::KNOWLEDGE_APPROVE, Permissions::KNOWLEDGE_ARCHIVE,
                    Permissions::PRODUCT_VIEW, Permissions::PERSONA_VIEW,
                    Permissions::INDUSTRY_VIEW, Permissions::INTENT_VIEW,
                    Permissions::SOURCE_VIEW, Permissions::SOURCE_APPROVE,
                    Permissions::CITATION_VIEW, Permissions::CITATION_APPROVE,
                    Permissions::CLAIM_VIEW, Permissions::CLAIM_APPROVE,
                    Permissions::BRAND_RULES_VIEW, Permissions::BRAND_RULES_APPROVE,
                    Permissions::CONTENT_POLICY_VIEW, Permissions::CONTENT_POLICY_APPROVE,
                    // Phase 2 content studio — view + review + approve + waive validations
                    Permissions::CONTENT_VIEW, Permissions::CONTENT_REVIEW,
                    Permissions::CONTENT_APPROVE, Permissions::CONTENT_REJECT,
                    Permissions::CONTENT_ARCHIVE,
                    Permissions::CONTENT_VERSION_VIEW,
                    Permissions::CONTENT_COMMENT_VIEW, Permissions::CONTENT_COMMENT_CREATE,
                    Permissions::CONTENT_COMMENT_RESOLVE,
                    Permissions::CONTENT_ASSIGNMENT_VIEW,
                    Permissions::CONTENT_VALIDATION_VIEW, Permissions::CONTENT_VALIDATION_MANAGE,
                    Permissions::CONTENT_VALIDATION_WAIVE,
                    Permissions::DAILY_PACK_VIEW,
                    Permissions::CONTENT_SCHEDULE_VIEW,
                    Permissions::PUBLICATION_TARGET_VIEW,
                    // Phase 3 AI — reviewers approve prompts, waive AI findings, view generations
                    Permissions::AI_VIEW, Permissions::AI_GENERATION_VIEW,
                    Permissions::AI_PROMPT_VIEW, Permissions::AI_PROMPT_APPROVE,
                    Permissions::AI_GROUNDING_VIEW,
                    Permissions::AI_VALIDATION_VIEW, Permissions::AI_VALIDATION_WAIVE,
                    Permissions::AI_USAGE_VIEW,
                ]))),
            ],
            [
                'slug' => 'analyst',
                'name' => 'Analyst',
                'description' => 'Read-only analytics + audit visibility.',
                'permissions' => array_values(array_unique(array_merge([
                    Permissions::DASHBOARD_VIEW,
                    Permissions::ANALYTICS_VIEW,
                    Permissions::AUDIT_VIEW,
                    Permissions::BLOG_VIEW, Permissions::CAMPAIGN_VIEW,
                    Permissions::SOCIAL_VIEW, Permissions::EMAIL_VIEW,
                    Permissions::WHATSAPP_VIEW, Permissions::LEAD_VIEW,
                ], $knowledgeViewOnly, $contentViewOnly, $aiViewOnly, [
                    Permissions::AI_USAGE_VIEW,
                ]))),
            ],
            [
                'slug' => 'viewer',
                'name' => 'Viewer',
                'description' => 'Dashboard + read-only view of published content.',
                'permissions' => array_values(array_unique(array_merge([
                    Permissions::DASHBOARD_VIEW,
                    Permissions::BLOG_VIEW, Permissions::CAMPAIGN_VIEW,
                    Permissions::SOCIAL_VIEW, Permissions::EMAIL_VIEW,
                    Permissions::WHATSAPP_VIEW,
                    // Phase 1: viewers see approved knowledge only (enforced at service layer)
                    Permissions::KNOWLEDGE_VIEW, Permissions::PRODUCT_VIEW,
                    Permissions::PERSONA_VIEW, Permissions::INDUSTRY_VIEW,
                    Permissions::CLAIM_VIEW, Permissions::SOURCE_VIEW,
                    // Phase 2: viewer can read content items and versions
                    Permissions::CONTENT_VIEW, Permissions::CONTENT_VERSION_VIEW,
                ], []))),
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
