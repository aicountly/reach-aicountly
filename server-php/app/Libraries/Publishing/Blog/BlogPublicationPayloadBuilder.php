<?php

namespace App\Libraries\Publishing\Blog;

use App\Libraries\Blog\BlogContentApprovalService;
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
    private BlogContentApprovalService $approvals;

    public function __construct(?BlogContentApprovalService $approvals = null)
    {
        $this->db        = \Config\Database::connect();
        $this->metadata  = new BlogMetadataService();
        $this->approvals = $approvals ?? new BlogContentApprovalService();
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

        // Sanitise HTML body — prefer version columns, then snapshot, then blog details.
        $rawSnapshot = $version['snapshot_json'] ?? null;
        $snapshot = is_string($rawSnapshot)
            ? (json_decode($rawSnapshot, true) ?? [])
            : (is_array($rawSnapshot) ? $rawSnapshot : []);
        $rawBody  = (string) ($version['body_html'] ?? $snapshot['body_html'] ?? $blogDetails['body_html'] ?? '');
        $safeBody = (new HtmlSanitizer())->purify($rawBody);

        // Placeholder bodies must never reach aicountly.com/blogs — the public
        // listing renders the excerpt verbatim, so an "Untitled draft........"
        // row shows up as a real article. Failing here marks the deployment
        // failed (PublicationDeploymentService catches it) instead of shipping
        // a fake post; `php spark reach:blog-listing-audit --fix` re-drafts it.
        $guard       = new PublishableContentGuard();
        $bodyVerdict = $guard->assessBody($safeBody, (string) ($item['title'] ?? ''));
        if (! $bodyVerdict['publishable']) {
            throw new \RuntimeException(
                'Content is not publishable: ' . implode('; ', $bodyVerdict['reasons'])
            );
        }

        // Internal-link automation: append the marked "Related reading"
        // section (2–4 registry links by product/category affinity) exactly
        // once at payload build. Prose is never rewritten; routine-authored
        // drafts that already carry the marker are left untouched. Each
        // injected link is recorded in reach_content_internal_links so the
        // INTERNAL_LINK block's HTTP validation covers it.
        try {
            $registry    = new \App\Libraries\Publishing\LinkRegistryService($this->db);
            $siteBase    = rtrim((string) env('AICOUNTLY_PUBLIC_SITE_BASE_URL', 'https://aicountly.com'), '/');
            $selfPath    = '/blogs/' . rawurlencode((string) ($item['slug'] ?? ''));
            $productSlug = $this->resolveProductSlug($contentItemId);
            $suggested   = $registry->suggestFor(
                $productSlug,
                (string) ($profile['category'] ?? ''),
                $selfPath,
                3,
            );
            $withLinks = $registry->appendRelatedSection($safeBody, $suggested, $siteBase);
            if ($withLinks !== $safeBody) {
                $safeBody = $withLinks;
                $linkService = new BlogInternalLinkService();
                foreach ($suggested as $link) {
                    $exists = $this->db->table('reach_content_internal_links')
                        ->where('source_content_item_id', $contentItemId)
                        ->where('target_public_url', $siteBase . $link['url_path'])
                        ->countAllResults() > 0;
                    if (! $exists) {
                        $linkService->addLink(
                            $contentItemId,
                            $contentVersionId,
                            $link['title'],
                            $siteBase . $link['url_path'],
                            null,
                            'auto_related_reading',
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // Link automation must never block a publish.
            log_message('warning', 'Link injection skipped: ' . $e->getMessage());
        }

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

        $bodyMarkdown = $version['body_markdown'] ?? $snapshot['body_markdown'] ?? null;

        // Named-reviewer attribution ("Reviewed by CA Rahul Gupta") may only surface when a
        // checksum-bound approval by a registered protected reviewer exists for this exact
        // version. The free-text reviewer_reference field can never produce this on its own.
        $protectedReviewerName = $this->approvals->protectedReviewerName($contentItemId, $contentVersionId);

        $payload = [
            'title'                => $item['title'] ?? '',
            'slug'                 => $seo['slug'] ?? $item['slug'] ?? '',
            // Listing cards show this: take the first candidate that reads as
            // real prose, never a stub excerpt saved alongside a good body.
            'excerpt'              => $guard->firstUsableExcerpt([
                $profile['excerpt'] ?? null,
                $version['summary'] ?? ($snapshot['summary'] ?? null),
                $seo['meta_description'] ?? null,
                $this->metadata->deriveExcerpt($safeBody),
            ]) ?: $this->metadata->deriveExcerpt($safeBody),
            'body_html'            => $safeBody,
            'body_markdown'        => $bodyMarkdown,
            'meta_title'           => $seo['meta_title'] ?? $item['title'] ?? '',
            'meta_description'     => $seo['meta_description'] ?? '',
            'canonical_preference' => $seo['canonical_preference'] ?? 'self_canonical',
            'robots_directive'     => $seo['robots_directive'] ?? 'index,follow',
            'category'             => $profile['category'] ?? '',
            'tags'                 => $tags,
            'author_name'          => $profile['author_reference'] ?? '',
            'reviewer_name'        => $protectedReviewerName ?? '',
            'reviewer_reference'   => $profile['reviewer_reference'] ?? '',
            'featured_image_url'   => $profile['featured_image_reference'] ?? $blogDetails['featured_image_url'] ?? '',
            'featured_image_alt'   => trim((string) ($profile['featured_image_alt'] ?? '')) !== ''
                                     ? $profile['featured_image_alt']
                                     : CoverAltTextBuilder::build(
                                         (string) ($item['title'] ?? ''),
                                         (string) ($profile['category'] ?? ''),
                                     ),
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

        $redirects = $this->pendingRedirects($contentItemId);
        if ($redirects !== []) {
            $payload['redirects'] = $redirects;
        }

        return $payload;
    }

    /**
     * Old URLs the public site should 301 to this post.
     *
     * Off by default. reach_publication_redirects has always been written (by
     * reach:blog-repair-slugs) and never read, so a slug repair followed by a
     * re-publish moves the post and leaves the previous URL 404ing. This is the
     * missing half — but whether the receiving site understands a `redirects`
     * key is a property of a different codebase, so sending it is opt-in:
     * BLOG_PUBLISH_REDIRECTS_ENABLED must be true.
     *
     * Verify with one post before enabling for the rest. If the receiver
     * ignores unknown fields the flag is harmless; if it validates strictly,
     * enabling it blindly would fail every publish.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingRedirects(int $contentItemId): array
    {
        if (! filter_var(env('BLOG_PUBLISH_REDIRECTS_ENABLED', false), FILTER_VALIDATE_BOOL)) {
            return [];
        }

        try {
            if (! \App\Libraries\Database\SchemaGuard::hasTable($this->db, 'reach_publication_redirects')) {
                return [];
            }

            $rows = $this->db->table('reach_publication_redirects')
                ->select('from_slug, to_slug, to_url, redirect_type')
                ->where('content_item_id', $contentItemId)
                ->whereIn('status', ['pending', 'active'])
                ->orderBy('id', 'ASC')
                ->get()->getResultArray();

            return array_map(static fn (array $r): array => [
                'from_path' => '/blogs/' . rawurlencode((string) $r['from_slug']),
                'to_path'   => '/blogs/' . rawurlencode((string) ($r['to_slug'] ?? '')),
                'to_url'    => $r['to_url'],
                'type'      => (int) ($r['redirect_type'] ?? 301),
            ], $rows);
        } catch (\Throwable $e) {
            log_message('warning', 'Redirect payload skipped: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Product affinity comes from the content-base candidate's "[product]"
     * note tag when present; absent one, suggestions fall back to
     * category/evergreen links.
     */
    private function resolveProductSlug(int $contentItemId): ?string
    {
        try {
            $notes = (string) ($this->db->table('reach_topic_candidates')
                ->select('notes')
                ->where('content_item_id', $contentItemId)
                ->get()->getRowArray()['notes'] ?? '');
            if (preg_match('/^\[product\]\s*(\S+)/m', $notes, $m)) {
                return $m[1];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Calculate SHA-256 checksum of the canonical payload JSON.
     */
    public function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
