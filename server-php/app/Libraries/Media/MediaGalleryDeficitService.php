<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Ai\Images\CoverPromptBuilder;
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
            $title  = (string) ($entry['title'] ?? '');
            $hint   = (string) ($entry['cover_prompt'] ?? '');
            $stream = (string) ($entry['portfolio_stream'] ?? '');

            $upcoming[] = [
                'key'          => (string) ($entry['key'] ?? ''),
                'title'        => $title,
                'target_date'  => $target,
                'status'       => $status,
                'stream'       => $stream,
                // The raw scene note from the content base, kept for reference.
                'cover_prompt' => $hint,
                // What an operator actually pastes into ChatGPT or Gemini. The
                // portfolio stream is deliberately NOT passed as the theme:
                // "marketing" is an editorial bucket, and handing it to an
                // image model puts megaphones and growth charts on a GST
                // article. The title and scene note carry the subject.
                'image_prompt' => (new CoverPromptBuilder())->buildForOperator($title, $hint),
                // Tags decide whether the finished upload can ever be matched
                // to this article, so the console should not make the operator
                // reverse-engineer the scorer's vocabulary.
                'suggested_tags' => $this->suggestedTags($title, $entry),
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

    /**
     * Tags that will actually match this article once the cover is uploaded.
     *
     * Derived with the scorer's own tokenizer, so what is suggested is what
     * will be compared: sub-3-character tokens are dropped (ai, hr, pf), as are
     * generic words (guide, complete, indian). One shared token already clears
     * the relevance floor; four gives room for the article's title to be
     * rewritten without stranding the cover.
     *
     * @param array<string,mixed> $entry
     * @return list<string>
     */
    private function suggestedTags(string $title, array $entry): array
    {
        $keywords = (new CoverRelevanceScorer())->articleKeywords(
            $title,
            (string) ($entry['product_slug'] ?? ''),
            array_map('strval', (array) ($entry['tags'] ?? [])),
            (string) ($entry['seed_keyword'] ?? ''),
        );

        return array_slice($keywords, 0, 4);
    }
}
