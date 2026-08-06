<?php

namespace Tests\Unit\Media;

use App\Libraries\Media\MediaAssetStore;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Cover binaries must be storable outside the deploy tree.
 *
 * Deploys rsync the API directory with --delete. While covers lived under
 * writable/uploads/, a protect rule that matched the directory but not its
 * contents erased every one of them on each release, leaving gallery rows
 * pointing at files that no longer existed. A path outside the document root
 * cannot be reached by that command at all.
 */
final class MediaStoragePathTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['MEDIA_STORAGE_PATH']);
        putenv('MEDIA_STORAGE_PATH');
    }

    protected function tearDown(): void
    {
        unset($_ENV['MEDIA_STORAGE_PATH']);
        putenv('MEDIA_STORAGE_PATH');
        parent::tearDown();
    }

    public function testDefaultsInsideWritableWhenUnset(): void
    {
        $path = (new MediaAssetStore())->storagePath();

        $this->assertStringEndsWith('/uploads/media/covers/', $path);
        $this->assertStringStartsWith(rtrim(WRITEPATH, '/'), $path);
    }

    public function testHonoursAnAbsoluteConfiguredPath(): void
    {
        $_ENV['MEDIA_STORAGE_PATH'] = '/home/reachaicountly/cover_images';

        $this->assertSame('/home/reachaicountly/cover_images/', (new MediaAssetStore())->storagePath());
    }

    public function testNormalisesATrailingSlash(): void
    {
        $_ENV['MEDIA_STORAGE_PATH'] = '/home/reachaicountly/cover_images/';

        // One trailing slash, never two — the store appends date('Y/m') to it.
        $this->assertSame('/home/reachaicountly/cover_images/', (new MediaAssetStore())->storagePath());
    }

    public function testAnExistingWritableDirectoryIsUsable(): void
    {
        $dir = sys_get_temp_dir() . '/covers-' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);
        $_ENV['MEDIA_STORAGE_PATH'] = $dir;

        $this->assertTrue((new MediaAssetStore())->storageWritable());

        rmdir($dir);
    }

    public function testADirectoryThatDoesNotExistYetIsUsableWhenItsParentIs(): void
    {
        // First upload creates it; requiring it to pre-exist would make a
        // fresh deployment look broken when it is merely new.
        $_ENV['MEDIA_STORAGE_PATH'] = sys_get_temp_dir() . '/covers-not-yet-' . bin2hex(random_bytes(4));

        $this->assertTrue((new MediaAssetStore())->storageWritable());
    }

    public function testAnUnreachablePathIsReportedAsNotWritable(): void
    {
        $_ENV['MEDIA_STORAGE_PATH'] = '/nonexistent-root-' . bin2hex(random_bytes(4)) . '/covers';

        $this->assertFalse((new MediaAssetStore())->storageWritable());
    }
}
