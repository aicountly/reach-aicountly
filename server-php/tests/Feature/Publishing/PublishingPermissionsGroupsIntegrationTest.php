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

    // -------------------------------------------------------------------------
    // Phase 5 — Community permission groups (Failure 3 regression)
    // -------------------------------------------------------------------------

    public function testCommunityGroupsExist(): void
    {
        $groups = Permissions::groups();
        foreach ([
            'community', 'community_intake', 'community_question',
            'community_answer', 'community_identity', 'community_settings',
            'community_analytics', 'community_audit', 'community_engagement',
        ] as $group) {
            $this->assertArrayHasKey($group, $groups, "Community group '{$group}' must exist in Permissions::groups()");
        }
    }

    public function testNoCommunityPermissionContainsMoreThanOneDot(): void
    {
        $all = Permissions::all();
        foreach ($all as $perm) {
            if (!str_starts_with($perm, 'community')) {
                continue;
            }
            $this->assertSame(
                1,
                substr_count($perm, '.'),
                "Community permission '{$perm}' must contain exactly one dot"
            );
        }
    }

    public function testPhase04PermissionsUnchanged(): void
    {
        $all = Permissions::all();
        // Spot-check a representative set of Phase 0–4 permissions
        $phase04 = [
            'dashboard.view',
            'blog.publish', 'blog.approve',
            'campaign.dispatch',
            'knowledge.approve',
            'content.approve',
            'ai.generate', 'ai_prompt.approve',
            'publishing.publish', 'seo.manage',
        ];
        foreach ($phase04 as $slug) {
            $this->assertContains($slug, $all, "Phase 0–4 permission '{$slug}' must remain present");
        }
    }
}
