<?php

namespace Tests\Feature\Publishing;

use Config\Permissions;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for Phase 4 publishing permissions.
 *
 * Verifies that all required publishing permission constants are defined and properly grouped.
 *
 * @group publishing
 */
class PublishingPermissionsIntegrationTest extends CIUnitTestCase
{
    public function testPublishingPermissionsAreDefined(): void
    {
        $required = [
            'publishing.view',
            'publishing.publish',
            'publishing.schedule',
            'publishing.unpublish',
            'publishing.rollback',
            'publishing.verify',
            'publishing.manage_connections',
            'publishing.manage_profiles',
        ];

        $defined = Permissions::all();
        foreach ($required as $perm) {
            $this->assertContains($perm, $defined, "Permission '{$perm}' must be defined");
        }
    }

    public function testSeoPermissionsAreDefined(): void
    {
        $required = [
            'seo.view',
            'seo.manage',
        ];

        $defined = Permissions::all();
        foreach ($required as $perm) {
            $this->assertContains($perm, $defined, "Permission '{$perm}' must be defined");
        }
    }

    public function testAeoPermissionsAreDefined(): void
    {
        $required = ['aeo.view', 'aeo.manage'];
        $defined  = Permissions::all();
        foreach ($required as $perm) {
            $this->assertContains($perm, $defined, "Permission '{$perm}' must be defined");
        }
    }

    public function testStructuredDataPermissionsAreDefined(): void
    {
        $required = ['structured_data.view', 'structured_data.manage'];
        $defined  = Permissions::all();
        foreach ($required as $perm) {
            $this->assertContains($perm, $defined, "Permission '{$perm}' must be defined");
        }
    }

    public function testPublishingGroupExists(): void
    {
        $groups = Permissions::groups();
        $this->assertArrayHasKey('publishing', $groups, 'publishing permission group must exist');
        $this->assertNotEmpty($groups['publishing']);
    }

    public function testSeoGroupExists(): void
    {
        $groups = Permissions::groups();
        $this->assertArrayHasKey('seo', $groups, 'seo permission group must exist');
    }

    public function testAeoGroupExists(): void
    {
        $groups = Permissions::groups();
        $this->assertArrayHasKey('aeo', $groups, 'aeo permission group must exist');
    }

    public function testAllPermissionsHaveGroupMembership(): void
    {
        $allPerms   = Permissions::all();
        $groupPerms = [];
        foreach (Permissions::groups() as $group => $perms) {
            foreach ($perms as $perm) {
                $groupPerms[] = $perm;
            }
        }

        foreach ($allPerms as $perm) {
            $this->assertContains($perm, $groupPerms, "Permission '{$perm}' must belong to a group");
        }
    }

    public function testPublishingVerificationPermissionsExist(): void
    {
        $defined = Permissions::all();
        $this->assertContains('publishing.verify', $defined);
    }
}
