<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Picks a curated gallery cover for a Claude-routine blog: least-used first,
 * tag/stream affinity when possible, and a reuse cooldown so the same image
 * never fronts two blogs within MEDIA_GALLERY_REUSE_COOLDOWN_DAYS. Row
 * locking (FOR UPDATE SKIP LOCKED) keeps concurrent assignments from
 * grabbing the same asset.
 */
class MediaGalleryAssignmentService
{
    private BaseConnection $db;
    private MediaAssetStore $store;

    public function __construct(?BaseConnection $db = null, ?MediaAssetStore $store = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->store = $store ?? new MediaAssetStore($this->db);
    }

    /**
     * @return array{asset_id:int, asset_uuid:string, public_url:string}|null
     */
    public function assignFor(int $contentItemId): ?array
    {
        $details = $this->db->table('reach_content_blog_details')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray() ?? [];
        $stream = trim((string) ($details['portfolio_stream'] ?? ''));

        $profile = $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray() ?? [];
        $category = trim((string) ($profile['category'] ?? ''));

        $cooldownDays = max(0, (int) env('MEDIA_GALLERY_REUSE_COOLDOWN_DAYS', 30));
        $cooldownSql  = $cooldownDays > 0
            ? "AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL '{$cooldownDays} days')"
            : '';

        // Pass 1: affinity match on category tag or portfolio stream.
        // Pass 2: any active, non-cooling asset.
        foreach ([true, false] as $withAffinity) {
            $affinitySql = '';
            $binds       = [];
            if ($withAffinity) {
                if ($category === '' && $stream === '') {
                    continue;
                }
                $clauses = [];
                if ($category !== '') {
                    $clauses[] = 'category_tags @> ?::jsonb';
                    $binds[]   = json_encode([$category]);
                }
                if ($stream !== '') {
                    $clauses[] = 'portfolio_stream = ?';
                    $binds[]   = $stream;
                }
                $affinitySql = 'AND (' . implode(' OR ', $clauses) . ')';
            }

            $row = $this->db->query(
                "SELECT id, asset_uuid, mime FROM reach_media_gallery_assets
                 WHERE status = 'active' AND kind = 'gallery_upload'
                 {$cooldownSql}
                 {$affinitySql}
                 ORDER BY times_used ASC, last_used_at ASC NULLS FIRST, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED",
                $binds
            )->getRowArray();

            if ($row) {
                $assetId = (int) $row['id'];
                $this->store->markUsed($assetId, $contentItemId);

                return [
                    'asset_id'   => $assetId,
                    'asset_uuid' => (string) $row['asset_uuid'],
                    'public_url' => $this->store->publicUrl($row),
                ];
            }
        }

        return null;
    }
}
