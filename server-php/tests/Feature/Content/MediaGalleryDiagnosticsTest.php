<?php

namespace Tests\Feature\Content;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * Cover serving fails closed on a missing MEDIA_SIGNING_KEY: publicUrl() still
 * mints a URL, but verifySignature() rejects every request, so all covers 404
 * — in the gallery and on aicountly.com, where published articles reference
 * the same URLs. That was invisible: the console rendered broken <img> tiles
 * with no explanation. The listing now reports the condition.
 */
final class MediaGalleryDiagnosticsTest extends ApiTestCase
{
    private function seedAsset(string $filePath): int
    {
        $db  = Database::connect();
        $key = bin2hex(random_bytes(8));

        $db->table('reach_media_gallery_assets')->insert([
            'asset_uuid'      => 'diag-' . $key,
            'kind'            => 'gallery_upload',
            'file_path'       => $filePath,
            'mime'            => 'image/webp',
            'bytes'           => 512,
            'checksum_sha256' => hash('sha256', $key),
            'category_tags'   => json_encode(['gst']),
            'status'          => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function list(): array
    {
        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('GET', 'v1/media/gallery');
        $this->assertSame(200, $response->response()->getStatusCode());

        return json_decode((string) $response->getJSON(), true)['data'];
    }

    public function testMissingSigningKeyIsReported(): void
    {
        $_ENV['MEDIA_SIGNING_KEY'] = '';
        putenv('MEDIA_SIGNING_KEY=');

        $this->seedAsset('/nonexistent/cover.webp');

        $this->assertFalse($this->list()['signing_key_configured']);
    }

    public function testConfiguredSigningKeyIsReported(): void
    {
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);

        $this->seedAsset('/nonexistent/cover.webp');

        $this->assertTrue($this->list()['signing_key_configured']);
    }

    public function testAssetsWithNoFileOnDiskAreFlagged(): void
    {
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);

        $present = tempnam(sys_get_temp_dir(), 'cover');
        file_put_contents($present, 'binary');
        $this->seedAsset($present);
        $this->seedAsset('/nonexistent/cover.webp');

        $data = $this->list();
        $this->assertSame(1, $data['files_missing']);

        $flags = array_column($data['assets'], 'file_missing');
        $this->assertContains(true, $flags);
        $this->assertContains(false, $flags);

        unlink($present);
    }

    public function testStorageLocationIsReported(): void
    {
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);
        $_ENV['MEDIA_STORAGE_PATH'] = '/home/reachaicountly/cover_images';

        $data = $this->list();

        $this->assertSame('/home/reachaicountly/cover_images/', $data['storage_path']);
        $this->assertTrue($data['storage_outside_deploy'], 'A path outside the API directory is out of rsync --delete reach.');

        unset($_ENV['MEDIA_STORAGE_PATH']);
    }

    public function testStorageInsideTheDeployTreeIsFlagged(): void
    {
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);
        unset($_ENV['MEDIA_STORAGE_PATH']);
        putenv('MEDIA_STORAGE_PATH');

        $data = $this->list();

        $this->assertFalse(
            $data['storage_outside_deploy'],
            'The default writable/ location sits inside the rsynced tree and must say so.',
        );
    }

    public function testTheEffectiveUploadLimitIsReported(): void
    {
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);

        $max = $this->list()['max_upload_bytes'];

        $this->assertGreaterThan(0, $max);
        $this->assertLessThanOrEqual(4 * 1024 * 1024, $max, 'The app cap is the ceiling.');

        // Whatever php.ini allows, the reported figure must not exceed it —
        // promising more is what produced "field is required" for a file the
        // operator could see attached.
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $raw = trim((string) ini_get($key));
            if ($raw === '' || $raw === '-1') {
                continue;
            }
            $bytes = (int) $raw * match (strtolower(substr($raw, -1))) {
                'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
            };
            $this->assertLessThanOrEqual($bytes, $max, "Reported limit exceeds php.ini {$key}.");
        }
    }

    public function testAnOversizeBodyIsReportedAsATooLargeUpload(): void
    {
        $headers = $this->authAs('super_admin');

        // Reproduces what PHP does past post_max_size: a content length with
        // no $_FILES at all. Without this branch the operator is told the
        // field is missing for a file they can see attached.
        $postMax = trim((string) ini_get('post_max_size'));
        if ($postMax === '' || $postMax === '-1') {
            $this->markTestSkipped('post_max_size is unlimited in this environment.');
        }

        $bytes = (int) $postMax * match (strtolower(substr($postMax, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };

        $_SERVER['CONTENT_LENGTH'] = (string) ($bytes + 1);
        $response = $this->withHeaders($headers)->call('POST', 'v1/media/gallery');
        unset($_SERVER['CONTENT_LENGTH']);

        $this->assertSame(413, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertStringContainsString('never reached the application', $body['error'] ?? '');
    }

    public function testAMissingFieldStillReadsAsAMissingField(): void
    {
        $headers  = $this->authAs('super_admin');
        $response = $this->withHeaders($headers)->call('POST', 'v1/media/gallery');

        $this->assertSame(422, $response->response()->getStatusCode());
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertStringContainsString('is required', $body['error'] ?? '');
    }

    public function testFilePathIsNeverExposed(): void
    {
        $_ENV['MEDIA_SIGNING_KEY'] = str_repeat('a', 64);
        $this->seedAsset('/nonexistent/cover.webp');

        foreach ($this->list()['assets'] as $asset) {
            $this->assertArrayNotHasKey('file_path', $asset, 'Server paths must not leak to the console.');
            $this->assertArrayHasKey('public_url', $asset);
        }
    }
}
