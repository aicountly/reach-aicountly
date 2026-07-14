<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityPermission;
use Config\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Validates that Phase 5 community permissions use the established
 * two-segment group.action format with no more than one dot.
 *
 * @covers \App\Enums\CommunityPermission
 */
final class CommunityPermissionTest extends TestCase
{
    private const EXPECTED_SLUGS = [
        'community.view',
        'community_intake.create',
        'community_intake.import',
        'community_question.edit',
        'community_question.classify',
        'community_question.moderate',
        'community_answer.generate',
        'community_answer.edit',
        'community_answer.review',
        'community_answer.professional_review',
        'community_answer.approve',
        'community_answer.schedule',
        'community_answer.publish',
        'community_answer.unpublish',
        'community_answer.restore',
        'community_answer.withdraw',
        'community_answer.override_validation',
        'community_identity.manage',
        'community_settings.manage',
        'community_analytics.view',
        'community_audit.view',
        'community_engagement.ingest',
    ];

    // -------------------------------------------------------------------------
    // Format constraints — Failure 3 regression tests
    // -------------------------------------------------------------------------

    public function testAllPermissionSlugsMatchGroupDotActionFormat(): void
    {
        foreach (CommunityPermission::cases() as $perm) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+\.[a-z_]+$/',
                $perm->value,
                "CommunityPermission::{$perm->name} value '{$perm->value}' must match group.action format"
            );
        }
    }

    public function testNoPermissionContainsMoreThanOneDot(): void
    {
        foreach (CommunityPermission::cases() as $perm) {
            $dotCount = substr_count($perm->value, '.');
            $this->assertSame(
                1,
                $dotCount,
                "CommunityPermission::{$perm->name} value '{$perm->value}' must contain exactly one dot"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Exact inventory
    // -------------------------------------------------------------------------

    public function testAllPermissionsHaveCommunityPrefix(): void
    {
        foreach (CommunityPermission::cases() as $perm) {
            $this->assertStringStartsWith(
                'community',
                $perm->value,
                "Permission {$perm->name} must start with 'community'"
            );
        }
    }

    public function testExactExpectedPermissionsArePresent(): void
    {
        $actual = array_map(fn($p) => $p->value, CommunityPermission::cases());
        foreach (self::EXPECTED_SLUGS as $slug) {
            $this->assertContains($slug, $actual, "Expected community permission '{$slug}' is missing from enum");
        }
    }

    public function testNoUnexpectedPermissionsExist(): void
    {
        $actual = array_map(fn($p) => $p->value, CommunityPermission::cases());
        foreach ($actual as $slug) {
            $this->assertContains($slug, self::EXPECTED_SLUGS, "Unexpected community permission '{$slug}' found in enum");
        }
    }

    public function testNoPermissionValueIsDuplicated(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertSame($values, array_unique($values), 'Community permission values must be unique');
    }

    public function testEnumCountMatchesExpectedList(): void
    {
        $this->assertCount(count(self::EXPECTED_SLUGS), CommunityPermission::cases());
    }

    // -------------------------------------------------------------------------
    // Canonical slug spot-checks
    // -------------------------------------------------------------------------

    public function testViewPermissionExists(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community.view', $values);
    }

    public function testAnswerPublishPermissionUsesCanonicalSlug(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community_answer.publish', $values);
        $this->assertNotContains('community.answer.publish', $values);
    }

    public function testAnswerApprovePermissionUsesCanonicalSlug(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community_answer.approve', $values);
        $this->assertNotContains('community.answer.approve', $values);
    }

    public function testAnswerWithdrawPermissionUsesCanonicalSlug(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community_answer.withdraw', $values);
        $this->assertNotContains('community.answer.withdraw', $values);
    }

    public function testIdentityManagePermissionUsesCanonicalSlug(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community_identity.manage', $values);
        $this->assertNotContains('community.identity.manage', $values);
    }

    public function testIntakeCreatePermissionUsesCanonicalSlug(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community_intake.create', $values);
        $this->assertNotContains('community.intake.create', $values);
    }

    // -------------------------------------------------------------------------
    // Config/Permissions alignment
    // -------------------------------------------------------------------------

    public function testEveryEnumPermissionExistsInPermissionsConfig(): void
    {
        $all = Permissions::all();
        foreach (CommunityPermission::cases() as $perm) {
            $this->assertContains(
                $perm->value,
                $all,
                "CommunityPermission::{$perm->name} ('{$perm->value}') is missing from Config\\Permissions::all()"
            );
        }
    }

    public function testEveryConfigCommunityPermissionExistsInEnum(): void
    {
        $enumValues = array_map(fn($p) => $p->value, CommunityPermission::cases());
        foreach (Permissions::all() as $slug) {
            if (!str_starts_with($slug, 'community')) {
                continue;
            }
            $this->assertContains(
                $slug,
                $enumValues,
                "Config permission '{$slug}' starting with 'community' has no matching CommunityPermission enum case"
            );
        }
    }
}
