<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Blog\ContentBaseService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Deficit = covers needed for upcoming index entries (next
 * MEDIA_GALLERY_LOOKAHEAD_DAYS) minus active, non-cooling gallery assets.
 * Surfaced on the console dashboard, the automation status endpoint, and a
 * daily superadmin email so the ~20-images/day personal-account budget is
 * spent on exactly the covers that are missing.
 */
class MediaGalleryDeficitService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{
     *   needed:int, available:int, untagged:int, deficit:int, lookahead_days:int,
     *   upcoming:list<array{key:string,title:string,target_date:string,cover_prompt:string,status:string}>
     * }
     */
    public function report(): array
    {
        $lookaheadDays = max(1, (int) env('MEDIA_GALLERY_LOOKAHEAD_DAYS', 14));
        $cooldownDays  = max(0, (int) env('MEDIA_GALLERY_REUSE_COOLDOWN_DAYS', 30));
        $horizon       = date('Y-m-d', strtotime("+{$lookaheadDays} days"));

        $index    = (new ContentBaseService($this->db))->blogIndex();
        $upcoming = [];
        foreach ((array) ($index['entries'] ?? []) as $entry) {
            $status = strtolower((string) ($entry['status'] ?? 'planned'));
            $target = (string) ($entry['target_date'] ?? '');
            if (! in_array($status, ['planned', 'drafting'], true)) {
                continue;
            }
            if ($target !== '' && $target > $horizon) {
                continue;
            }
            $upcoming[] = [
                'key'          => (string) ($entry['key'] ?? ''),
                'title'        => (string) ($entry['title'] ?? ''),
                'target_date'  => $target,
                'cover_prompt' => (string) ($entry['cover_prompt'] ?? ''),
                'status'       => $status,
            ];
        }

        $cooldownSql = $cooldownDays > 0
            ? "AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL '{$cooldownDays} days')"
            : '';
        $available = 0;
        $untagged  = 0;

        try {
            // Assignment is relevance-scored, so an asset with neither tags nor
            // a portfolio stream can never be matched to an article — it is
            // dead stock, not availability. Counted separately so operators can
            // fix it by tagging rather than by uploading more images.
            $row = $this->db->query(
                "SELECT
                     COUNT(*) FILTER (
                         WHERE category_tags <> '[]'::jsonb OR portfolio_stream IS NOT NULL
                     ) AS matchable,
                     COUNT(*) FILTER (
                         WHERE category_tags = '[]'::jsonb AND portfolio_stream IS NULL
                     ) AS untagged
                 FROM reach_media_gallery_assets
                 WHERE status = 'active' AND kind = 'gallery_upload' {$cooldownSql}"
            )->getRowArray();
            $available = (int) ($row['matchable'] ?? 0);
            $untagged  = (int) ($row['untagged'] ?? 0);
        } catch (\Throwable) {
        }

        $needed = count($upcoming);

        return [
            'needed'         => $needed,
            'available'      => $available,
            'untagged'       => $untagged,
            'deficit'        => max(0, $needed - $available),
            'lookahead_days' => $lookaheadDays,
            'upcoming'       => $upcoming,
        ];
    }
}
