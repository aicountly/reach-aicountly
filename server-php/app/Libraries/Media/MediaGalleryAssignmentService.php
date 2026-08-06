<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Picks a curated gallery cover for a Claude-routine blog.
 *
 * Selection is relevance-first: candidate assets are scored against the
 * article's category, portfolio stream, tags and title keywords
 * (CoverRelevanceScorer) and anything below the relevance floor is discarded
 * — a GST article never gets "next photo in the rotation". Among equally
 * relevant assets the least-recently-used one wins, so rotation and the reuse
 * cooldown (MEDIA_GALLERY_REUSE_COOLDOWN_DAYS) still hold. Row locking
 * (FOR UPDATE SKIP LOCKED) keeps concurrent assignments off the same asset.
 *
 * No relevant asset → null, and the caller falls back to generating a cover
 * from the article itself rather than attaching an unrelated image.
 */
class MediaGalleryAssignmentService
{
    /** Rows scored in PHP per assignment; ordered LRU-first so the tail is the least useful. */
    private const CANDIDATE_LIMIT = 120;

    private BaseConnection $db;
    private MediaAssetStore $store;
    private CoverRelevanceScorer $scorer;

    public function __construct(?BaseConnection $db = null, ?MediaAssetStore $store = null, ?CoverRelevanceScorer $scorer = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->store  = $store ?? new MediaAssetStore($this->db);
        $this->scorer = $scorer ?? new CoverRelevanceScorer();
    }

    /**
     * @return array{asset_id:int, asset_uuid:string, public_url:string, relevance_score:int, match_reasons:list<string>}|null
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

        $item = $this->db->table('reach_content_items')
            ->select('title')
            ->where('id', $contentItemId)
            ->get()->getRowArray() ?? [];

        $keywords = $this->scorer->articleKeywords(
            (string) ($item['title'] ?? ''),
            $category,
            $this->profileTags($profile),
            (string) ($details['search_intent'] ?? ''),
        );

        $candidates = $this->candidates();
        $ranked     = $this->scorer->rank($candidates, $keywords, $category, $stream);

        // Operators can opt back into "any free cover" when the gallery is not
        // yet tagged; the default is to publish without a hero instead.
        if ($ranked === [] && CoverRelevanceScorer::allowUnmatchedFallback()) {
            $ranked = array_map(
                static fn (array $asset): array => ['asset' => $asset, 'score' => 0, 'reasons' => ['unmatched_fallback']],
                $candidates,
            );
        }

        foreach ($ranked as $row) {
            $locked = $this->lock((int) $row['asset']['id']);
            if ($locked === null) {
                continue; // Another worker took it — try the next best.
            }

            // A row whose binary is gone is not a cover. Assigning it would
            // set featured_image_reference to a URL that 404s, pass the
            // cover gate, and publish an article with a broken hero on
            // aicountly.com — the exact outcome the gate exists to prevent.
            if (! is_file((string) ($locked['file_path'] ?? ''))) {
                log_message('error', sprintf(
                    'Gallery asset %d has no file at %s; skipping assignment. Run `php spark reach:media-reconcile`.',
                    (int) $locked['id'],
                    (string) ($locked['file_path'] ?? '')
                ));

                continue;
            }

            $assetId = (int) $locked['id'];
            $this->store->markUsed($assetId, $contentItemId);

            return [
                'asset_id'        => $assetId,
                'asset_uuid'      => (string) $locked['asset_uuid'],
                'public_url'      => $this->store->publicUrl($locked),
                'relevance_score' => (int) $row['score'],
                'match_reasons'   => $row['reasons'],
            ];
        }

        return null;
    }

    /**
     * Active, non-cooling gallery assets, least-used first.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(): array
    {
        $cooldownDays = max(0, (int) env('MEDIA_GALLERY_REUSE_COOLDOWN_DAYS', 30));
        $cooldownSql  = $cooldownDays > 0
            ? "AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL '{$cooldownDays} days')"
            : '';

        $limit = self::CANDIDATE_LIMIT;

        return $this->db->query(
            "SELECT id, asset_uuid, mime, category_tags, portfolio_stream, prompt_used
             FROM reach_media_gallery_assets
             WHERE status = 'active' AND kind = 'gallery_upload'
             {$cooldownSql}
             ORDER BY times_used ASC, last_used_at ASC NULLS FIRST, id ASC
             LIMIT {$limit}"
        )->getResultArray();
    }

    /**
     * Re-read the chosen row under a lock so two workers cannot claim it.
     *
     * @return array<string,mixed>|null
     */
    private function lock(int $assetId): ?array
    {
        return $this->db->query(
            "SELECT id, asset_uuid, mime, file_path FROM reach_media_gallery_assets
             WHERE id = ? AND status = 'active'
             FOR UPDATE SKIP LOCKED",
            [$assetId]
        )->getRowArray() ?: null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return list<string>
     */
    private function profileTags(array $profile): array
    {
        $tags = $profile['tags_json'] ?? [];
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?? [];
        }

        return is_array($tags) ? array_values(array_map('strval', $tags)) : [];
    }
}
