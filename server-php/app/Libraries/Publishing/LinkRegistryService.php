<?php

declare(strict_types=1);

namespace App\Libraries\Publishing;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Internal-link automation over the canonical URL inventory
 * (reach_link_registry). Suggestion is deterministic — product/category
 * affinity first, then recency — and injection only ever APPENDS a clearly
 * marked "Related reading" section; article prose is never rewritten by a
 * link pass.
 */
class LinkRegistryService
{
    public const MARKER = '<!-- reach-related-links -->';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** Upsert a published blog/KB URL into the registry (called on publish). */
    public function recordPublished(string $urlPath, string $title, string $linkType, ?string $productSlug = null, ?string $category = null, ?int $contentItemId = null): void
    {
        $this->db->query(
            'INSERT INTO reach_link_registry (url_path, title, link_type, product_slug, category, content_item_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (url_path) DO UPDATE SET
                title = EXCLUDED.title,
                status = \'active\',
                product_slug = EXCLUDED.product_slug,
                category = EXCLUDED.category,
                content_item_id = EXCLUDED.content_item_id,
                updated_at = NOW()',
            [$urlPath, mb_substr($title, 0, 300), $linkType, $productSlug, $category, $contentItemId]
        );
    }

    public function retire(string $urlPath): void
    {
        $this->db->table('reach_link_registry')
            ->where('url_path', $urlPath)
            ->update(['status' => 'retired', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Deterministic suggestions for a content item: same product first, then
     * same category, then evergreen product/guide pages. Never suggests the
     * item's own URL.
     *
     * @return list<array{url_path:string, title:string, link_type:string}>
     */
    public function suggestFor(?string $productSlug, ?string $category, ?string $excludeUrlPath, int $limit = 3): array
    {
        $limit = max(1, min(6, $limit));
        $rows  = [];
        $seen  = [];

        $collect = function (array $result) use (&$rows, &$seen, $excludeUrlPath, $limit): void {
            foreach ($result as $row) {
                if (count($rows) >= $limit) {
                    return;
                }
                $path = (string) $row['url_path'];
                if ($path === $excludeUrlPath || isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                $rows[]      = ['url_path' => $path, 'title' => (string) $row['title'], 'link_type' => (string) $row['link_type']];
            }
        };

        if ($productSlug !== null && $productSlug !== '') {
            $collect($this->db->query(
                "SELECT url_path, title, link_type FROM reach_link_registry
                 WHERE status = 'active' AND product_slug = ? ORDER BY link_type = 'product' DESC, updated_at DESC LIMIT 6",
                [$productSlug]
            )->getResultArray());
        }
        if (count($rows) < $limit && $category !== null && $category !== '') {
            $collect($this->db->query(
                "SELECT url_path, title, link_type FROM reach_link_registry
                 WHERE status = 'active' AND category = ? ORDER BY updated_at DESC LIMIT 6",
                [$category]
            )->getResultArray());
        }
        if (count($rows) < $limit) {
            $collect($this->db->query(
                "SELECT url_path, title, link_type FROM reach_link_registry
                 WHERE status = 'active' AND link_type IN ('product', 'guide', 'static')
                 ORDER BY updated_at DESC LIMIT 8"
            )->getResultArray());
        }

        return $rows;
    }

    /**
     * Appends the marked "Related reading" section to a body when absent.
     *
     * @param list<array{url_path:string, title:string}> $links
     */
    public function appendRelatedSection(string $bodyHtml, array $links, string $siteBase): string
    {
        if ($links === [] || str_contains($bodyHtml, self::MARKER)) {
            return $bodyHtml;
        }

        $items = '';
        foreach ($links as $link) {
            $href   = rtrim($siteBase, '/') . $link['url_path'];
            $items .= '<li><a href="' . htmlspecialchars($href, ENT_QUOTES) . '">'
                . htmlspecialchars($link['title'], ENT_QUOTES) . '</a></li>';
        }

        return $bodyHtml
            . "\n" . self::MARKER
            . '<section class="related-reading"><h2>Related reading</h2><ul>' . $items . '</ul></section>';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function excerpt(int $limit = 100): array
    {
        return $this->db->table('reach_link_registry')
            ->select('url_path, title, link_type, product_slug, category')
            ->where('status', 'active')
            ->orderBy('link_type', 'ASC')
            ->orderBy('updated_at', 'DESC')
            ->limit(max(1, min(300, $limit)))
            ->get()->getResultArray();
    }
}
