<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Libraries\Video\VideoAssetGuard;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoAssetGuardTest extends CIUnitTestCase
{
    private VideoAssetGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new VideoAssetGuard(524288000);
    }

    public function testBuildStorageKeyIsIsolatedByTenant(): void
    {
        $key1 = $this->guard->buildStorageKey(1, 'aaaabbbb-cccc-dddd-eeee-ffffaaaabbbb', 'mp4');
        $key2 = $this->guard->buildStorageKey(2, 'aaaabbbb-cccc-dddd-eeee-ffffaaaabbbb', 'mp4');
        $this->assertStringStartsWith('video/1/', $key1);
        $this->assertStringStartsWith('video/2/', $key2);
        $this->assertNotSame($key1, $key2);
    }

    public function testBuildStorageKeyStripsPathTraversal(): void
    {
        $key = $this->guard->buildStorageKey(1, '../../etc/passwd', 'mp4');
        $this->assertStringNotContainsString('..', $key);
        $this->assertStringNotContainsString('/', substr($key, strlen('video/1/')));
    }

    public function testBuildStorageKeyUsesExpectedFormat(): void
    {
        $key = $this->guard->buildStorageKey(42, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'mp4');
        $this->assertMatchesRegularExpression('/^video\/42\/[a-f0-9\-]+\.mp4$/', $key);
    }

    public function testValidateReturnsErrorForMissingFile(): void
    {
        $result = $this->guard->validate(['tmp_name' => '', 'name' => 'test.mp4', 'size' => 1000]);
        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testValidateReturnsErrorForOversizedFile(): void
    {
        $guard  = new VideoAssetGuard(1000);
        $result = $guard->validate(['tmp_name' => __FILE__, 'name' => 'test.mp4', 'size' => 2000]);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds maximum', $result['error']);
    }
}
