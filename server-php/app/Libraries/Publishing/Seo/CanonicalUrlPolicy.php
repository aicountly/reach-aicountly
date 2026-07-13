<?php

namespace App\Libraries\Publishing\Seo;

/**
 * Phase 4 — Canonical URL policy resolution.
 *
 * Resolves the canonical URL for content based on the configured preference.
 * Slug changes trigger redirect planning.
 */
class CanonicalUrlPolicy
{
    private string $siteBaseUrl;

    public function __construct(?string $siteBaseUrl = null)
    {
        $this->siteBaseUrl = rtrim($siteBaseUrl ?? (string) ($_ENV['AICOUNTLY_SITE_URL'] ?? 'https://aicountly.com'), '/');
    }

    /**
     * Resolve the canonical URL for a content item.
     *
     * @param string $contentType  'blog' or 'knowledge_base'
     * @param string $slug         Clean slug (already validated)
     * @param string $preference   One of the 5 canonical preference values
     * @param string|null $existingUrl  If canonical_to_existing, the target URL
     */
    public function resolve(
        string $contentType,
        string $slug,
        string $preference = 'self_canonical',
        ?string $existingUrl = null
    ): string {
        switch ($preference) {
            case 'self_canonical':
                return $this->buildUrl($contentType, $slug);

            case 'canonical_to_existing':
                if (empty($existingUrl)) {
                    throw new \InvalidArgumentException('canonical_to_existing requires an existingUrl');
                }
                return $existingUrl;

            case 'noindex':
            case 'historical_archive':
                return $this->buildUrl($contentType, $slug);

            case 'redirect_to_existing':
                if (empty($existingUrl)) {
                    throw new \InvalidArgumentException('redirect_to_existing requires an existingUrl');
                }
                return $existingUrl;

            default:
                throw new \InvalidArgumentException("Unknown canonical preference: {$preference}");
        }
    }

    /**
     * Build the path-based URL for a content item.
     */
    public function buildUrl(string $contentType, string $slug): string
    {
        $pathPrefix = match ($contentType) {
            'blog'           => '/blog/',
            'knowledge_base' => '/help/',
            default          => '/',
        };

        return $this->siteBaseUrl . $pathPrefix . ltrim($slug, '/');
    }

    /**
     * Determine if a slug change requires a redirect record.
     */
    public function requiresRedirect(string $oldSlug, string $newSlug): bool
    {
        return !empty($oldSlug) && $oldSlug !== $newSlug;
    }

    /**
     * Validate slug format.
     */
    public function isValidSlug(string $slug): bool
    {
        return !empty($slug) && (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
    }
}
