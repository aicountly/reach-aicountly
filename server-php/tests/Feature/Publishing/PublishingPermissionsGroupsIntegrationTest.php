<?php

namespace Tests\Feature\Publishing;

use Config\Permissions;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for publishing permission groups.
 *
 * @group publishing
 */
class PublishingPermissionsGroupsIntegrationTest extends CIUnitTestCase
{
    public function testAllPublishingGroupPermissionsAreValid(): void
    {
        $groups  = Permissions::groups();
        $all     = Permissions::all();

        $this->assertArrayHasKey('publishing', $groups);
        foreach ($groups['publishing'] as $perm) {
            $this->assertContains($perm, $all, "Permission '{$perm}' in publishing group must be in all()");
        }
    }

    public function testAllSeoGroupPermissionsAreValid(): void
    {
        $groups = Permissions::groups();
        $all    = Permissions::all();

        $this->assertArrayHasKey('seo', $groups);
        foreach ($groups['seo'] as $perm) {
            $this->assertContains($perm, $all, "Permission '{$perm}' in seo group must be in all()");
        }
    }

    public function testAllAeoGroupPermissionsAreValid(): void
    {
        $groups = Permissions::groups();
        $all    = Permissions::all();

        $this->assertArrayHasKey('aeo', $groups);
        foreach ($groups['aeo'] as $perm) {
            $this->assertContains($perm, $all, "Permission '{$perm}' in aeo group must be in all()");
        }
    }

    public function testAllStructuredDataGroupPermissionsAreValid(): void
    {
        $groups = Permissions::groups();
        $all    = Permissions::all();

        $this->assertArrayHasKey('structured_data', $groups);
        foreach ($groups['structured_data'] as $perm) {
            $this->assertContains($perm, $all, "Permission '{$perm}' in structured_data group must be in all()");
        }
    }

    public function testPublishingGroupContainsAtLeastFivePermissions(): void
    {
        $groups = Permissions::groups();
        $this->assertGreaterThanOrEqual(5, count($groups['publishing']));
    }

    public function testNoDuplicatePermissionsInAnyGroup(): void
    {
        $groups = Permissions::groups();
        foreach ($groups as $groupName => $perms) {
            $unique = array_unique($perms);
            $this->assertSame(
                count($unique), count($perms),
                "Group '{$groupName}' must not have duplicate permissions"
            );
        }
    }

    public function testPermissionSlugFormatIsDotted(): void
    {
        $all = Permissions::all();
        foreach ($all as $perm) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[a-z_]+$/',
                $perm,
                "Permission '{$perm}' must be in format group.action"
            );
        }
    }

    public function testKbPublishingGroupExists(): void
    {
        $groups = Permissions::groups();
        $this->assertArrayHasKey('kb_publishing', $groups);
    }
}
