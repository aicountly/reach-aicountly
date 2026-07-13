<?php

namespace App\Libraries\Publishing\Blog;

use App\Libraries\HtmlSanitizer;

/**
 * Phase 4 — Assembles the safe, approved publication payload for blog content.
 *
 * Only approved and sanitised fields are included.
 * The payload is checksummed with SHA-256.
 */
class BlogPublicationPayloadBuilder
{
    private \CodeIgniter\Database\BaseConnection $db;
    private BlogMetadataService $metadata;

    public function __construct()
    {
        $this->db       = \Config\Database::connect();
        $this->metadata = new BlogMetadataService();
    }

    /**
     * Build the full payload array for the given content item + version.
     *
     * @throws \RuntimeException if required data is missing or content not approved
     */
    public function build(int $contentItemId, int $contentVersionId): array
    {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)->get()->getRowArray();

        if (!$item || $item['approval_status'] !== 'approved') {
            throw new \RuntimeException('Content must be human-approved before payload can be built');
        }

        $version = $this->db->table('reach_content_versions')
            ->where('id', $contentVersionId)
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if (!$version) {
            throw new \RuntimeException('Content version not found');
        }

        $profile = $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];

        $seo = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];

        $blogDetails = $this->db->table('reach_content_blog_details')
            ->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];

        // Sanitise HTML body
        $snapshot = is_string($version['snapshot_json']) ? json_decode($version['snapshot_json'], true) : ($version['snapshot_json'] ?? []);
        $rawBody  = $snapshot['body_html'] ?? $blogDetails['body_html'] ?? '';
        $safeBody = HtmlSanitizer::sanitize($rawBody);

        // Structured data
        $structuredData = $this->db->table('reach_content_structured_data')
            ->where('content_item_id', $contentItemId)
            ->whereIn('validation_status', ['valid', 'pending'])
            ->get()->getResultArray();

        $sdArray = array_map(
            fn($row) => json_decode($row['schema_json'], true),
            $structuredData
        );

        // Internal links
        $links = $this->db->table('reach_content_internal_links')
            ->where('source_content_item_id', $contentItemId)
            ->where('status', 'active')
            ->get()->getResultArray();

        $internalLinks = array_map(fn($l) => [
            'anchor'     => $l['anchor_text'] ?? '',
            'url'        => $l['target_public_url'] ?? '',
            'rel'        => 'nofollow',
        ], $links);

        // FAQ from AEO profile
        $aeo = $this->db->table('reach_content_aeo_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray();

        $faqCandidates = [];
        if ($aeo && !empty($aeo['faq_candidates_json'])) {
            $faqCandidates = json_decode($aeo['faq_candidates_json'], true) ?? [];
        }

        $tags = $profile['tags_json'] ?? '[]';
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?? [];
        }

        $bodyMarkdown = $snapshot['body_markdown'] ?? null;

        $payload = [
            'title'                => $item['title'] ?? '',
            'slug'                 => $seo['slug'] ?? $item['slug'] ?? '',
            'excerpt'              => !empty($profile['excerpt'])
                                     ? $profile['excerpt']
                                     : $this->metadata->deriveExcerpt($safeBody),
            'body_html'            => $safeBody,
            'body_markdown'        => $bodyMarkdown,
            'meta_title'           => $seo['meta_title'] ?? $item['title'] ?? '',
            'meta_description'     => $seo['meta_description'] ?? '',
            'canonical_preference' => $seo['canonical_preference'] ?? 'self_canonical',
            'robots_directive'     => $seo['robots_directive'] ?? 'index,follow',
            'category'             => $profile['category'] ?? '',
            'tags'                 => $tags,
            'author_name'          => $profile['author_reference'] ?? '',
            'reviewer_name'        => $profile['reviewer_reference'] ?? '',
            'featured_image_url'   => $profile['featured_image_reference'] ?? $blogDetails['featured_image_url'] ?? '',
            'featured_image_alt'   => $profile['featured_image_alt'] ?? '',
            'internal_links'       => $internalLinks,
            'citations'            => [],
            'faq'                  => $faqCandidates,
            'structured_data'      => $sdArray,
            'language'             => $item['language'] ?? 'en',
            'market'               => $item['market_id'] ?? null,
            'reading_time_minutes' => $profile['reading_time_minutes']
                                     ?? $this->metadata->estimateReadingTime($safeBody),
            'scheduled_at'         => null,
        ];

        return $payload;
    }

    /**
     * Calculate SHA-256 checksum of the canonical payload JSON.
     */
    public function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
