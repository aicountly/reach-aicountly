<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Picks a gallery cover for a blog (routine or calendar) — an operator
 * upload or one of Reach's own stocked covers (kind ai_gallery).
 *
 * Selection is relevance-first: candidate assets are scored against the
 * article's category, portfolio stream, tags and title keywords
 * (CoverRelevanceScorer) and anything below the relevance floor is discarded
 * — a GST article never gets "next photo in the rotation". Among equally
 * relevant assets a never-used one wins, then the least-recently-used one, so
 * rotation and the reuse cooldown (MEDIA_GALLERY_REUSE_COOLDOWN_DAYS) still
 * hold. A cover stocked for the article's own content-base entry
 * (source_key) outranks everything. Row locking
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

    /** Gallery kinds an article may be fronted with: operator uploads and Reach's own stocked covers. */
    public const GALLERY_KINDS = ['gallery_upload', 'ai_gallery'];

    /**
     * What the scorer needs to know about an article.
     *
     * @return array{title:string, category:string, stream:string, tags:list<string>, keywords:list<string>, content_base_key:string}
     */
    public function articleContext(int $contentItemId): array
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
            ->where('id', $contentItemId)
            ->get()->getRowArray() ?? [];

        $tags     = $this->profileTags($profile);
        $keywords = $this->scorer->articleKeywords(
            (string) ($item['title'] ?? ''),
            $category,
            $tags,
            (string) ($details['search_intent'] ?? ''),
        );

        return [
            'title'            => (string) ($item['title'] ?? ''),
            'category'         => $category,
            'stream'           => $stream,
            'tags'             => $tags,
            'keywords'         => $keywords,
            'content_base_key' => $this->contentBaseKey($contentItemId, $item),
        ];
    }

    /**
     * @return array{asset_id:int, asset_uuid:string, public_url:string, relevance_score:int, match_reasons:list<string>, branded:bool}|null
     */
    public function assignFor(int $contentItemId): ?array
    {
        $context    = $this->articleContext($contentItemId);
        $candidates = $this->candidates();
        $ranked     = $this->scorer->rank(
            $candidates,
            $context['keywords'],
            $context['category'],
            $context['stream'],
            null,
            $context['content_base_key'],
        );

        // Never-used covers first (a cover seen on another post is the
        // "same photo everywhere" look), then by relevance; usort is stable
        // in PHP 8, so equal rows keep the scorer's least-recently-used order.
        // No relevant asset → null: the caller generates a cover from the
        // article instead of attaching an unrelated one (the old "any free
        // cover" fallback is retired).
        usort($ranked, static fn (array $a, array $b): int => ((int) ($a['asset']['times_used'] ?? 0) > 0) <=> ((int) ($b['asset']['times_used'] ?? 0) > 0)
            ?: $b['score'] <=> $a['score']);

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

            // Covers carry the Aicountly corner mark. Older uploads predate
            // that; brand them now (the original is kept). If branding cannot
            // run, the relevant cover is still better than none.
            $locked = $this->store->ensureBranded($locked);

            $assetId = (int) $locked['id'];
            $this->store->markUsed($assetId, $contentItemId);

            return [
                'asset_id'        => $assetId,
                'asset_uuid'      => (string) $locked['asset_uuid'],
                'public_url'      => $this->store->publicUrl($locked),
                'relevance_score' => (int) $row['score'],
                'match_reasons'   => $row['reasons'],
                'branded'         => ! empty($locked['branded']) && $locked['branded'] !== 'f',
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
        $kinds     = "'" . implode("','", self::GALLERY_KINDS) . "'";
        $sourceKey = \App\Libraries\Database\SchemaGuard::hasColumn($this->db, 'reach_media_gallery_assets', 'source_key') ? ', source_key' : '';

        $limit = self::CANDIDATE_LIMIT;

        return $this->db->query(
            "SELECT id, asset_uuid, mime, category_tags, portfolio_stream, prompt_used, times_used{$sourceKey}
             FROM reach_media_gallery_assets
             WHERE status = 'active' AND kind IN ({$kinds})
             {$cooldownSql}
             ORDER BY times_used ASC, last_used_at ASC NULLS FIRST, id ASC
             LIMIT {$limit}"
        )->getResultArray();
    }

    /**
     * The article's content-base entry key: set on the item by routine
     * submissions, or reachable through the topic candidate it came from.
     *
     * @param array<string,mixed> $item
     */
    private function contentBaseKey(int $contentItemId, array $item): string
    {
        $key = trim((string) ($item['content_base_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        try {
            if (\App\Libraries\Database\SchemaGuard::hasColumn($this->db, 'reach_topic_candidates', 'content_base_key')) {
                $row = $this->db->table('reach_topic_candidates')
                    ->select('content_base_key')
                    ->where('content_item_id', $contentItemId)
                    ->where('content_base_key IS NOT NULL', null, false)
                    ->limit(1)->get()->getRowArray();

                return trim((string) ($row['content_base_key'] ?? ''));
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * Re-read the chosen row under a lock so two workers cannot claim it.
     *
     * @return array<string,mixed>|null
     */
    private function lock(int $assetId): ?array
    {
        return $this->db->query(
            "SELECT * FROM reach_media_gallery_assets
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
