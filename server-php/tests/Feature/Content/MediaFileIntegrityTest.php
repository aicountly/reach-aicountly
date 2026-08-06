<?php

namespace Tests\Feature\Content;

use App\Libraries\Media\MediaGalleryAssignmentService;
use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * A gallery row whose binary is gone is not a cover.
 *
 * Deploys rsync the API directory with --delete, and the protect rule for
 * writable/uploads/ matched only the directory entry, not the tree beneath it
 * — so every cover binary was deleted on each release while its database row
 * survived. Assignment did not check, so an article could be given a
 * featured_image_reference that 404s, clear the cover gate, and publish with a
 * broken hero on aicountly.com.
 */
final class MediaFileIntegrityTest extends ApiTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function seedAsset(array $tags, bool $withFile): int
    {
        $db   = Database::connect();
        $key  = bin2hex(random_bytes(8));
        $path = sys_get_temp_dir() . '/integrity-' . $key . '.webp';

        if ($withFile) {
            file_put_contents($path, 'fake-webp-binary');
            $this->tempFiles[] = $path;
        }

        $db->table('reach_media_gallery_assets')->insert([
            'asset_uuid'      => 'int-' . $key,
            'kind'            => 'gallery_upload',
            'file_path'       => $path,
            'mime'            => 'image/webp',
            'bytes'           => 16,
            'checksum_sha256' => hash('sha256', $key),
            'category_tags'   => json_encode($tags),
            'status'          => 'active',
        ]);

        return (int) $db->insertID();
    }

    private function seedArticle(string $title): int
    {
        $db  = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->table('reach_content_items')->insert([
            'uuid'            => bin2hex(random_bytes(16)),
            'content_type'    => 'blog',
            'title'           => $title,
            'slug'            => 'integrity-' . bin2hex(random_bytes(5)),
            'workflow_status' => 'fact_verified',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return (int) $db->insertID();
    }

    public function testAnAssetWithNoFileOnDiskIsNeverAssigned(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $this->seedAsset(['gst', 'returns'], withFile: false);
        $itemId = $this->seedArticle('GST returns filing deadlines');

        $assignment = (new MediaGalleryAssignmentService())->assignFor($itemId);

        $this->assertNull($assignment, 'A row whose binary is gone must not be handed out as a cover.');
    }

    public function testAssignmentFallsThroughToAnAssetThatStillExists(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        // The broken one sorts first (lower id, never used), so a naive
        // implementation would pick it and stop.
        $broken = $this->seedAsset(['gst', 'returns'], withFile: false);
        $good   = $this->seedAsset(['gst', 'returns'], withFile: true);
        $itemId = $this->seedArticle('GST returns filing deadlines');

        $assignment = (new MediaGalleryAssignmentService())->assignFor($itemId);

        $this->assertNotNull($assignment);
        $this->assertSame($good, $assignment['asset_id']);
        $this->assertNotSame($broken, $assignment['asset_id']);
    }

    public function testReconcileReportsAndRetiresMissingAssets(): void
    {
        if (! self::hasTestDatabase()) {
            $this->markTestSkipped(self::missingTestDatabaseReason());
        }

        $db      = Database::connect();
        $missing = $this->seedAsset(['gst'], withFile: false);
        $present = $this->seedAsset(['gst'], withFile: true);

        // Report only — nothing changes.
        command('reach:media-reconcile');
        $this->assertSame('active', $db->table('reach_media_gallery_assets')->where('id', $missing)->get()->getRowArray()['status']);

        command('reach:media-reconcile --fix');

        $this->assertSame(
            'retired',
            $db->table('reach_media_gallery_assets')->where('id', $missing)->get()->getRowArray()['status'],
            'A cover that no longer exists must leave the rotation.',
        );
        $this->assertSame(
            'active',
            $db->table('reach_media_gallery_assets')->where('id', $present)->get()->getRowArray()['status'],
            'Assets whose file is intact must be left alone.',
        );
    }
}
