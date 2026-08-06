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
