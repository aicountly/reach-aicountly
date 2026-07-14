<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityPermission;
use PHPUnit\Framework\TestCase;

final class CommunityPermissionTest extends TestCase
{
    public function testAllPermissionsHaveCommunityPrefix(): void
    {
        foreach (CommunityPermission::cases() as $perm) {
            $this->assertStringStartsWith('community.', $perm->value, "Permission {$perm->name} must start with 'community.'");
        }
    }

    public function testViewPermissionExists(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community.view', $values);
    }

    public function testAnswerPublishPermissionExists(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community.answer.publish', $values);
    }

    public function testAnswerApprovePermissionExists(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community.answer.approve', $values);
    }

    public function testAnswerWithdrawPermissionExists(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community.answer.withdraw', $values);
    }

    public function testIdentityManagePermissionExists(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertContains('community.identity.manage', $values);
    }

    public function testAtLeast22PermissionsExist(): void
    {
        $this->assertGreaterThanOrEqual(22, count(CommunityPermission::cases()));
    }

    public function testNoPermissionValueIsDuplicated(): void
    {
        $values = array_map(fn($p) => $p->value, CommunityPermission::cases());
        $this->assertSame($values, array_unique($values));
    }
}
