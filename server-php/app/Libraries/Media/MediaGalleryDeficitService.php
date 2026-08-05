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
     *   needed:int, available:int, deficit:int, lookahead_days:int,
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

        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS c FROM reach_media_gallery_assets
                 WHERE status = 'active' AND kind = 'gallery_upload' {$cooldownSql}"
            )->getRowArray();
            $available = (int) ($row['c'] ?? 0);
        } catch (\Throwable) {
        }

        $needed = count($upcoming);

        return [
            'needed'         => $needed,
            'available'      => $available,
            'deficit'        => max(0, $needed - $available),
            'lookahead_days' => $lookaheadDays,
            'upcoming'       => $upcoming,
        ];
    }
}
