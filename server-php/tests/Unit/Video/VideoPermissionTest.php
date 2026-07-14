<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Enums\VideoPermission;
use Config\Permissions;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoPermissionTest extends CIUnitTestCase
{
    public function testAllEnumValuesUseTwoSegmentFormat(): void
    {
        foreach (VideoPermission::cases() as $case) {
            $slug = $case->value;
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z_]*\.[a-z][a-z_]*$/',
                $slug,
                "VideoPermission::{$case->name} = '{$slug}' must use group.action format"
            );
            $this->assertSame(
                1,
                substr_count($slug, '.'),
                "VideoPermission::{$case->name} = '{$slug}' must have exactly one dot"
            );
        }
    }

    public function testAllEnumValuesAreUnique(): void
    {
        $values = array_map(fn($c) => $c->value, VideoPermission::cases());
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function testAllEnumValuesRegisteredInPermissionsConfig(): void
    {
        $all = Permissions::all();
        foreach (VideoPermission::cases() as $case) {
            $this->assertContains(
                $case->value,
                $all,
                "VideoPermission::{$case->name} = '{$case->value}' must appear in Config\\Permissions::all()"
            );
        }
    }

    public function testVideoGroupExistsInPermissionsConfig(): void
    {
        $groups = Permissions::groups();
        $this->assertArrayHasKey('video', $groups);
        $this->assertArrayHasKey('video_connections', $groups);
        $this->assertArrayHasKey('video_operations', $groups);
        $this->assertArrayHasKey('video_audit', $groups);
    }

    public function testPermissionsConfigHasNoVideoPermissionDuplicates(): void
    {
        $all = Permissions::all();
        $video = array_filter($all, fn($p) => str_starts_with($p, 'video'));
        $this->assertSame(count($video), count(array_unique($video)));
    }
}
