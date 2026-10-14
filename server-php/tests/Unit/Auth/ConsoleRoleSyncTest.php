<?php

namespace Tests\Unit\Auth;

use App\Libraries\ConsoleRoleSync;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pure role-decision rules for Console-managed Reach roles (no DB, no HTTP).
 */
final class ConsoleRoleSyncTest extends CIUnitTestCase
{
    private function identity(array $extra = []): array
    {
        return array_merge([
            'active'            => true,
            'global_superadmin' => false,
            'user'              => ['id' => 77, 'name' => 'Ravi', 'email' => 'ravi@aicountly.org', 'role' => 'staff'],
            'controller_grants' => [],
            'app_code'          => 'reach',
        ], $extra);
    }

    public function testRolesAreRankedHighestFirst(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity([
            'app_roles' => ['content_reviewer', 'marketing_manager'], 'app_superadmin' => false,
            'console_user_id' => 77, 'authz_version' => 4,
        ]));

        $this->assertSame('grant', $d['status']);
        $this->assertSame(['marketing_manager', 'content_reviewer'], $d['roles']);
        $this->assertSame('marketing_manager', ConsoleRoleSync::highest($d['roles']));
        $this->assertSame(77, $d['console_user_id']);
        $this->assertSame(4, $d['authz_version']);
    }

    public function testFullRanking(): void
    {
        $this->assertSame(
            ['super_admin', 'reach_admin', 'marketing_manager', 'content_reviewer'],
            ConsoleRoleSync::rankRoles(['content_reviewer', 'reach_admin', 'super_admin', 'marketing_manager'], false),
        );
        $this->assertSame('reach_admin', ConsoleRoleSync::highest(['content_reviewer', 'reach_admin']));
    }

    public function testEmptyAppRolesWithoutSuperadminIsDenied(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['app_roles' => [], 'app_superadmin' => false]));

        $this->assertSame('deny', $d['status']);
        $this->assertSame('Access not assigned. Ask a Console superadmin to assign you a role.', $d['message']);
    }

    public function testUnknownSlugsAreIgnored(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['app_roles' => ['member', 'analyst'], 'app_superadmin' => false]));

        $this->assertSame('deny', $d['status']);
    }

    public function testAppSuperadminWins(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['app_roles' => ['content_reviewer'], 'app_superadmin' => true]));

        $this->assertSame('super_admin', ConsoleRoleSync::highest($d['roles']));
    }

    public function testPayloadWithoutAppRolesIsLegacy(): void
    {
        $d = ConsoleRoleSync::fromIdentity($this->identity(['global_superadmin' => true]));

        $this->assertSame('legacy', $d['status']);
        $this->assertTrue($d['superadmin']);
        $this->assertSame(77, $d['console_user_id']);
    }

    public function testUserAccessDenials(): void
    {
        foreach ([
            ['exists' => false, 'active' => true, 'app_access' => true, 'app_roles' => ['reach_admin']],
            ['exists' => true, 'active' => false, 'app_access' => true, 'app_roles' => ['reach_admin']],
            ['exists' => true, 'active' => true, 'app_access' => false, 'app_roles' => ['reach_admin']],
            ['exists' => true, 'active' => true, 'app_access' => true, 'app_roles' => []],
        ] as $data) {
            $d = ConsoleRoleSync::fromUserAccess($data);
            $this->assertSame('deny', $d['status']);
            $this->assertTrue($d['revoked']);
        }
    }

    public function testGraceWindowIsTwelveHours(): void
    {
        $now = 1_800_000_000;
        $this->assertTrue(ConsoleRoleSync::withinGrace(gmdate('Y-m-d H:i:s', $now - 3600) . '+00', $now));
        $this->assertFalse(ConsoleRoleSync::withinGrace(gmdate('Y-m-d H:i:s', $now - 13 * 3600) . '+00', $now));
        $this->assertFalse(ConsoleRoleSync::withinGrace(null, $now));
    }
}
