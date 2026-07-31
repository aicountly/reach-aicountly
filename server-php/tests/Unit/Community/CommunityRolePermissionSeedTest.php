<?php

namespace Tests\Unit\Community;

use App\Database\Seeds\RolesAndPermissionsSeeder;
use App\Enums\CommunityPermission;
use App\Libraries\PermissionService;
use Config\Permissions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Behavioural RBAC tests for the Phase 5 community role grants.
 *
 * These assert the *effect* of the seeded role definitions when resolved
 * through the production PermissionService matching logic (exact match,
 * `group.*` expansion, bare `*`), not merely that constants exist.
 *
 * Regression target: all 22 community.* permissions existed but were assigned
 * to no seeded role, so only super_admin's `*` made the module reachable.
 *
 * @covers \App\Database\Seeds\RolesAndPermissionsSeeder
 * @covers \App\Libraries\PermissionService
 */
final class CommunityRolePermissionSeedTest extends TestCase
{
    private const SUPER_ADMIN = 'super_admin';

    /** @var array<string, string[]> slug => raw seeded permission list */
    private array $rolePermissions = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RolesAndPermissionsSeeder::roleDefinitions() as $role) {
            $this->rolePermissions[$role['slug']] = $role['permissions'];
        }
    }

    // -------------------------------------------------------------------------
    // Helpers — resolution goes through the real PermissionService logic
    // -------------------------------------------------------------------------

    /**
     * Resolves a permission for a role using PermissionService::hasPermission(),
     * with the DB-backed lookup replaced by the seeded role definition.
     */
    private function roleCan(string $slug, string $permission): bool
    {
        $this->assertArrayHasKey($slug, $this->rolePermissions, "Role '{$slug}' is not seeded");

        return $this->serviceFor($this->rolePermissions[$slug])->hasPermission(1, $permission);
    }

    /**
     * @param string[] $rawPermissions
     */
    private function serviceFor(array $rawPermissions): PermissionService
    {
        return new class ($rawPermissions) extends PermissionService {
            /** @param string[] $rawPermissions */
            public function __construct(private array $rawPermissions)
            {
            }

            public function resolveEffective(int $userId): array
            {
                // Reuse the production wildcard expansion so `group.*` grants and
                // bare `*` behave exactly as they do against a real role row.
                $expand = new ReflectionMethod(PermissionService::class, 'expandWildcards');
                $expand->setAccessible(true);
                $expanded = $expand->invoke($this, $this->rawPermissions);
                sort($expanded);

                return $expanded;
            }
        };
    }

    /** @return string[] */
    private function nonSuperAdminRoles(): array
    {
        return array_values(array_filter(
            array_keys($this->rolePermissions),
            static fn(string $slug): bool => $slug !== self::SUPER_ADMIN
        ));
    }

    // -------------------------------------------------------------------------
    // Core regression — every community permission is reachable without `*`
    // -------------------------------------------------------------------------

    public function testEveryCommunityPermissionIsGrantedToAtLeastOneNonSuperAdminRole(): void
    {
        $roles = $this->nonSuperAdminRoles();

        foreach (CommunityPermission::cases() as $perm) {
            $holders = array_values(array_filter(
                $roles,
                fn(string $slug): bool => $this->roleCan($slug, $perm->value)
            ));

            $this->assertNotEmpty(
                $holders,
                "Community permission '{$perm->value}' is granted to no non-super_admin role; "
                . 'the module would only be reachable via the super_admin wildcard'
            );
        }
    }

    public function testEveryCommunityRoleGrantsAtLeastCommunityViewOrIngest(): void
    {
        foreach ($this->nonSuperAdminRoles() as $slug) {
            if (! str_starts_with($slug, 'community_')) {
                continue;
            }

            $this->assertTrue(
                $this->roleCan($slug, Permissions::COMMUNITY_VIEW),
                "Community role '{$slug}' must be able to read the community module"
            );
        }
    }

    public function testCommunityRolesAreSeeded(): void
    {
        foreach ([
            'community_viewer', 'community_contributor', 'community_reviewer',
            'community_professional_approver', 'community_moderator', 'community_publisher',
            'community_manager', 'community_automation_admin', 'community_auditor',
            'community_service_account',
        ] as $slug) {
            $this->assertArrayHasKey($slug, $this->rolePermissions, "Role '{$slug}' must be seeded");
            $this->assertNotEmpty($this->rolePermissions[$slug], "Role '{$slug}' must have permissions");
        }
    }

    // -------------------------------------------------------------------------
    // Separation of duties — content creation
    // -------------------------------------------------------------------------

    public function testContributorCanCreateIntakeAndGenerateAnswers(): void
    {
        $this->assertTrue($this->roleCan('community_contributor', Permissions::COMMUNITY_INTAKE_CREATE));
        $this->assertTrue($this->roleCan('community_contributor', Permissions::COMMUNITY_ANSWER_GENERATE));
        $this->assertTrue($this->roleCan('community_contributor', Permissions::COMMUNITY_ANSWER_EDIT));
        $this->assertTrue($this->roleCan('community_contributor', Permissions::COMMUNITY_QUESTION_EDIT));
    }

    public function testContributorCannotApprovePublishOrOverrideValidation(): void
    {
        $this->assertFalse(
            $this->roleCan('community_contributor', Permissions::COMMUNITY_ANSWER_APPROVE),
            'A content creator must never approve its own answers'
        );
        $this->assertFalse(
            $this->roleCan('community_contributor', Permissions::COMMUNITY_ANSWER_PUBLISH),
            'A content creator must never publish'
        );
        $this->assertFalse(
            $this->roleCan('community_contributor', Permissions::COMMUNITY_ANSWER_OVERRIDE_VALIDATION),
            'A content creator must never override validation'
        );
        $this->assertFalse(
            $this->roleCan('community_contributor', Permissions::COMMUNITY_SETTINGS_MANAGE),
            'A content creator must never manage community settings'
        );
    }

    // -------------------------------------------------------------------------
    // Separation of duties — review vs approval vs publication
    // -------------------------------------------------------------------------

    public function testReviewerCanReviewButCannotApproveOrPublish(): void
    {
        $this->assertTrue($this->roleCan('community_reviewer', Permissions::COMMUNITY_ANSWER_REVIEW));
        $this->assertTrue($this->roleCan('community_reviewer', Permissions::COMMUNITY_QUESTION_CLASSIFY));
        $this->assertFalse(
            $this->roleCan('community_reviewer', Permissions::COMMUNITY_ANSWER_APPROVE),
            'A reviewer must not hold the approval gate'
        );
        $this->assertFalse(
            $this->roleCan('community_reviewer', Permissions::COMMUNITY_ANSWER_PUBLISH),
            'A reviewer must not publish'
        );
    }

    public function testProfessionalApproverCanApproveButCannotPublish(): void
    {
        $this->assertTrue(
            $this->roleCan('community_professional_approver', Permissions::COMMUNITY_ANSWER_APPROVE),
            'The professional approver must hold the approval gate'
        );
        $this->assertTrue(
            $this->roleCan('community_professional_approver', Permissions::COMMUNITY_ANSWER_PROFESSIONAL_REVIEW)
        );
        $this->assertFalse(
            $this->roleCan('community_professional_approver', Permissions::COMMUNITY_ANSWER_PUBLISH),
            'Approval and publication must be held by different roles'
        );
        $this->assertFalse(
            $this->roleCan('community_professional_approver', Permissions::COMMUNITY_ANSWER_SCHEDULE),
            'Approval and scheduling must be held by different roles'
        );
    }

    public function testPublisherCanPublishButCannotApproveOrGenerate(): void
    {
        $this->assertTrue($this->roleCan('community_publisher', Permissions::COMMUNITY_ANSWER_PUBLISH));
        $this->assertTrue($this->roleCan('community_publisher', Permissions::COMMUNITY_ANSWER_SCHEDULE));
        $this->assertFalse(
            $this->roleCan('community_publisher', Permissions::COMMUNITY_ANSWER_APPROVE),
            'A publisher must not approve what it publishes'
        );
        $this->assertFalse(
            $this->roleCan('community_publisher', Permissions::COMMUNITY_ANSWER_GENERATE),
            'A publisher must not author answers'
        );
        $this->assertFalse(
            $this->roleCan('community_publisher', Permissions::COMMUNITY_ANSWER_OVERRIDE_VALIDATION),
            'A publisher must not override validation'
        );
    }

    public function testModeratorCanTakeDownButCannotApprovePublishOrGenerate(): void
    {
        $this->assertTrue($this->roleCan('community_moderator', Permissions::COMMUNITY_QUESTION_MODERATE));
        $this->assertTrue($this->roleCan('community_moderator', Permissions::COMMUNITY_ANSWER_UNPUBLISH));
        $this->assertTrue($this->roleCan('community_moderator', Permissions::COMMUNITY_ANSWER_RESTORE));
        $this->assertTrue($this->roleCan('community_moderator', Permissions::COMMUNITY_ANSWER_WITHDRAW));
        $this->assertFalse(
            $this->roleCan('community_moderator', Permissions::COMMUNITY_ANSWER_APPROVE),
            'A moderator must not approve'
        );
        $this->assertFalse(
            $this->roleCan('community_moderator', Permissions::COMMUNITY_ANSWER_PUBLISH),
            'A moderator must not publish'
        );
        $this->assertFalse(
            $this->roleCan('community_moderator', Permissions::COMMUNITY_ANSWER_GENERATE),
            'A moderator must not author answers'
        );
    }

    public function testManagerHoldsOperationsButNotApprovalGateOrValidationOverride(): void
    {
        $this->assertTrue($this->roleCan('community_manager', Permissions::COMMUNITY_SETTINGS_MANAGE));
        $this->assertTrue($this->roleCan('community_manager', Permissions::COMMUNITY_IDENTITY_MANAGE));
        $this->assertTrue($this->roleCan('community_manager', Permissions::COMMUNITY_ANSWER_PUBLISH));
        $this->assertFalse(
            $this->roleCan('community_manager', Permissions::COMMUNITY_ANSWER_OVERRIDE_VALIDATION),
            'Validation override is break-glass and must not sit on the manager role'
        );
        $this->assertFalse(
            $this->roleCan('community_manager', Permissions::COMMUNITY_ANSWER_APPROVE),
            'Professional approval must stay with the professional approver role'
        );
    }

    public function testAutomationAdminConfiguresButCannotApproveOrPublish(): void
    {
        $this->assertTrue($this->roleCan('community_automation_admin', Permissions::COMMUNITY_SETTINGS_MANAGE));
        $this->assertTrue($this->roleCan('community_automation_admin', Permissions::COMMUNITY_ENGAGEMENT_INGEST));
        $this->assertTrue($this->roleCan('community_automation_admin', Permissions::COMMUNITY_ANALYTICS_VIEW));
        $this->assertFalse(
            $this->roleCan('community_automation_admin', Permissions::COMMUNITY_ANSWER_APPROVE),
            'The automation admin must not approve'
        );
        $this->assertFalse(
            $this->roleCan('community_automation_admin', Permissions::COMMUNITY_ANSWER_PUBLISH),
            'The automation admin must not publish'
        );
    }

    // -------------------------------------------------------------------------
    // Machine and read-only actors
    // -------------------------------------------------------------------------

    public function testServiceAccountCanOnlyIngestEngagement(): void
    {
        $this->assertTrue($this->roleCan('community_service_account', Permissions::COMMUNITY_ENGAGEMENT_INGEST));
        $this->assertTrue($this->roleCan('community_service_account', Permissions::COMMUNITY_VIEW));

        foreach ([
            Permissions::COMMUNITY_ANSWER_PUBLISH,
            Permissions::COMMUNITY_ANSWER_APPROVE,
            Permissions::COMMUNITY_ANSWER_GENERATE,
            Permissions::COMMUNITY_ANSWER_SCHEDULE,
            Permissions::COMMUNITY_ANSWER_OVERRIDE_VALIDATION,
            Permissions::COMMUNITY_SETTINGS_MANAGE,
            Permissions::COMMUNITY_IDENTITY_MANAGE,
        ] as $forbidden) {
            $this->assertFalse(
                $this->roleCan('community_service_account', $forbidden),
                "A service account must never hold '{$forbidden}'"
            );
        }
    }

    public function testAuditorHoldsOnlyReadPermissions(): void
    {
        $this->assertTrue($this->roleCan('community_auditor', Permissions::COMMUNITY_AUDIT_VIEW));
        $this->assertTrue($this->roleCan('community_auditor', Permissions::COMMUNITY_ANALYTICS_VIEW));

        foreach ($this->rolePermissions['community_auditor'] as $perm) {
            $action = explode('.', $perm, 2)[1] ?? '';
            $this->assertContains(
                $action,
                ['view', 'read'],
                "Auditor permission '{$perm}' is not read-only"
            );
        }

        foreach (CommunityPermission::cases() as $perm) {
            $action = explode('.', $perm->value, 2)[1] ?? '';
            if (in_array($action, ['view', 'read'], true)) {
                continue;
            }
            $this->assertFalse(
                $this->roleCan('community_auditor', $perm->value),
                "An auditor must not hold the mutating permission '{$perm->value}'"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Wildcard hygiene
    // -------------------------------------------------------------------------

    public function testOnlySuperAdminHoldsBareWildcard(): void
    {
        $this->assertContains('*', $this->rolePermissions[self::SUPER_ADMIN]);

        foreach ($this->nonSuperAdminRoles() as $slug) {
            $this->assertNotContains(
                '*',
                $this->rolePermissions[$slug],
                "Role '{$slug}' must not hold the bare '*' wildcard"
            );
        }
    }

    public function testSuperAdminWildcardStillResolvesCommunityPermissions(): void
    {
        foreach (CommunityPermission::cases() as $perm) {
            $this->assertTrue(
                $this->roleCan(self::SUPER_ADMIN, $perm->value),
                "super_admin must retain '{$perm->value}' via the wildcard"
            );
        }
    }

    public function testNoRoleGrantsAnUnknownCommunityPermission(): void
    {
        foreach ($this->rolePermissions as $slug => $perms) {
            foreach ($perms as $perm) {
                if (! str_starts_with($perm, 'community')) {
                    continue;
                }
                $this->assertTrue(
                    Permissions::isKnown($perm),
                    "Role '{$slug}' grants unknown community permission '{$perm}'"
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Group registration — `community_*.*` wildcards must resolve
    // -------------------------------------------------------------------------

    public function testCommunityGroupWildcardsResolveThroughPermissionService(): void
    {
        $svc = $this->serviceFor(['community_answer.*']);

        $this->assertTrue($svc->hasPermission(1, Permissions::COMMUNITY_ANSWER_PUBLISH));
        $this->assertTrue($svc->hasPermission(1, Permissions::COMMUNITY_ANSWER_APPROVE));
        $this->assertFalse(
            $svc->hasPermission(1, Permissions::COMMUNITY_SETTINGS_MANAGE),
            'community_answer.* must not leak into other community groups'
        );
    }

    public function testEveryCommunityPermissionBelongsToARegisteredGroup(): void
    {
        $groups = Permissions::groups();

        foreach (CommunityPermission::cases() as $perm) {
            $group = explode('.', $perm->value, 2)[0];
            $this->assertArrayHasKey(
                $group,
                $groups,
                "Group '{$group}' for permission '{$perm->value}' is not registered in Permissions::groups()"
            );
            $this->assertContains(
                $perm->value,
                $groups[$group],
                "Permission '{$perm->value}' is not listed under group '{$group}'"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Existing roles keep their pre-Phase-5 grants
    // -------------------------------------------------------------------------

    public function testExistingRolesGainCommunityAccessWithoutLosingPriorGrants(): void
    {
        $this->assertTrue($this->roleCan('reach_admin', Permissions::COMMUNITY_VIEW));
        $this->assertTrue($this->roleCan('reach_admin', Permissions::COMMUNITY_INTAKE_IMPORT));
        $this->assertTrue($this->roleCan('reach_admin', Permissions::COMMUNITY_ANSWER_OVERRIDE_VALIDATION));

        $this->assertTrue($this->roleCan('content_reviewer', Permissions::COMMUNITY_ANSWER_REVIEW));
        $this->assertFalse(
            $this->roleCan('content_reviewer', Permissions::COMMUNITY_ANSWER_PUBLISH),
            'content_reviewer must not gain community publication rights'
        );

        $this->assertTrue($this->roleCan('analyst', Permissions::COMMUNITY_ANALYTICS_VIEW));
        $this->assertTrue($this->roleCan('viewer', Permissions::COMMUNITY_ANALYTICS_VIEW));

        // Spot-check pre-existing grants are untouched.
        $this->assertTrue($this->roleCan('reach_admin', Permissions::BLOG_PUBLISH));
        $this->assertTrue($this->roleCan('content_reviewer', Permissions::CONTENT_APPROVE));
        $this->assertTrue($this->roleCan('analyst', Permissions::AUDIT_VIEW));
        $this->assertTrue($this->roleCan('viewer', Permissions::DASHBOARD_VIEW));
    }
}
