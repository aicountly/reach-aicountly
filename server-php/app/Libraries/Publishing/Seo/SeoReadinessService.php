<?php

namespace App\Libraries\Publishing\Seo;

/**
 * Phase 4 — SEO readiness validation for content items.
 *
 * Evaluates SEO criteria. Findings are stored in reach_content_seo_profiles.
 * Scores are transparent; they never constitute the sole approval criterion.
 */
class SeoReadinessService
{
    private \CodeIgniter\Database\BaseConnection $db;

    private const TITLE_MIN = 30;
    private const TITLE_MAX = 70;
    private const META_DESC_MIN = 100;
    private const META_DESC_MAX = 165;
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const KEYWORD_STUFFING_RATIO = 0.04;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Run all SEO checks and update the SEO profile.
     *
     * @return array{status: string, findings: array<int,array>}
     */
    public function evaluate(int $contentItemId): array
    {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)->get()->getRowArray();

        $seo = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray();

        $version = $this->db->table('reach_content_versions')
            ->where('content_item_id', $contentItemId)
            ->orderBy('version_number', 'DESC')
            ->limit(1)->get()->getRowArray();

        $snapshot = [];
        if ($version) {
            $snapshot = is_string($version['snapshot_json'])
                ? json_decode($version['snapshot_json'], true) ?? []
                : ($version['snapshot_json'] ?? []);
        }

        $bodyText  = strip_tags($snapshot['body_html'] ?? '');
        $title     = $item['title'] ?? '';
        $metaTitle = $seo['meta_title'] ?? '';
        $metaDesc  = $seo['meta_description'] ?? '';
        $slug      = $seo['slug'] ?? $item['slug'] ?? '';
        $keyword   = $seo['primary_keyword'] ?? '';

        $findings  = [];
        $blocking  = false;

        // Title checks
        $this->check($findings, $blocking, !empty($title), 'error', 'title_missing', 'Title is missing');
        if (!empty($title)) {
            $len = mb_strlen($title);
            $this->check($findings, $blocking, $len >= self::TITLE_MIN, 'warning', 'title_too_short', "Title is too short ({$len} chars, min " . self::TITLE_MIN . ")");
            $this->check($findings, $blocking, $len <= self::TITLE_MAX, 'warning', 'title_too_long', "Title is too long ({$len} chars, max " . self::TITLE_MAX . ")");
        }

        // Meta title
        if (!empty($metaTitle)) {
            $len = mb_strlen($metaTitle);
            $this->check($findings, $blocking, $len >= self::TITLE_MIN, 'warning', 'meta_title_too_short', "Meta title too short ({$len} chars)");
            $this->check($findings, $blocking, $len <= self::TITLE_MAX, 'warning', 'meta_title_too_long', "Meta title too long ({$len} chars)");
        }

        // Meta description
        $this->check($findings, $blocking, !empty($metaDesc), 'error', 'meta_description_missing', 'Meta description is missing', true);
        if (!empty($metaDesc)) {
            $len = mb_strlen($metaDesc);
            $this->check($findings, $blocking, $len >= self::META_DESC_MIN, 'warning', 'meta_description_too_short', "Meta description too short ({$len} chars)");
            $this->check($findings, $blocking, $len <= self::META_DESC_MAX, 'warning', 'meta_description_too_long', "Meta description too long ({$len} chars)");
        }

        // Slug
        $this->check($findings, $blocking, !empty($slug), 'error', 'slug_missing', 'SEO slug is not defined', true);
        if (!empty($slug)) {
            $this->check($findings, $blocking, (bool) preg_match(self::SLUG_PATTERN, $slug), 'error', 'slug_invalid_format', "Slug '{$slug}' contains invalid characters", true);

            // Slug uniqueness
            $duplicate = $this->db->table('reach_content_seo_profiles')
                ->where('slug', $slug)
                ->where('content_item_id !=', $contentItemId)
                ->countAllResults();
            $this->check($findings, $blocking, $duplicate === 0, 'error', 'slug_not_unique', "Slug '{$slug}' is already used by another content item", true);
        }

        // Keyword stuffing
        if (!empty($keyword) && !empty($bodyText)) {
            $wordCount = str_word_count($bodyText);
            if ($wordCount > 0) {
                $keywordCount = substr_count(strtolower($bodyText), strtolower($keyword));
                $ratio = $keywordCount / $wordCount;
                $this->check($findings, $blocking, $ratio <= self::KEYWORD_STUFFING_RATIO, 'warning', 'keyword_stuffing', sprintf("Keyword density %.1f%% exceeds recommended maximum", $ratio * 100));
            }
        }

        // Internal links
        $linkCount = $this->db->table('reach_content_internal_links')
            ->where('source_content_item_id', $contentItemId)
            ->where('status', 'active')
            ->countAllResults();
        $this->check($findings, $blocking, $linkCount > 0, 'warning', 'no_internal_links', 'No internal links found (recommended: at least 1)');

        // Canonical preference
        $this->check($findings, $blocking, !empty($seo['canonical_preference']), 'error', 'canonical_missing', 'Canonical preference is not defined', true);

        // Author info
        $profile = $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray();
        $hasAuthor = !empty($profile['author_reference']);
        $this->check($findings, $blocking, $hasAuthor, 'warning', 'no_author', 'Author information is not defined');

        $status = $blocking ? 'blocked' : (empty($findings) ? 'ready' : 'warning');

        // Update SEO profile
        if ($seo) {
            $this->db->table('reach_content_seo_profiles')
                ->where('content_item_id', $contentItemId)
                ->update([
                    'seo_status'    => $status,
                    'findings_json' => json_encode($findings),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
        }

        return ['status' => $status, 'findings' => $findings];
    }

    private function check(
        array &$findings,
        bool &$blocking,
        bool $condition,
        string $level,
        string $code,
        string $message,
        bool $isBlocking = false
    ): void {
        if (!$condition) {
            $findings[] = ['level' => $level, 'code' => $code, 'message' => $message];
            if ($isBlocking || $level === 'error') {
                $blocking = true;
            }
        }
    }
}
