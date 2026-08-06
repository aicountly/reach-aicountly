<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Publishing\Seo\CanonicalUrlPolicy;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Keeps reach_content_identities in step with what is actually live on the
 * public site.
 *
 * Search Console reports performance against URLs. Facts are stored against a
 * content identity, so without this table every ingested row is discarded as
 * "unmapped" — which is exactly what used to happen: nothing ever wrote to
 * reach_content_identities, so the whole search pipeline had no landing point.
 *
 * The canonical URL is taken from the publication deployment that actually put
 * the post live, and only falls back to the slug-derived URL when a deployment
 * recorded none. Nothing is invented: an item with no live deployment and no
 * slug is skipped rather than given a guessed URL.
 */
class ContentIdentitySyncService
{
    /** Deployment states that mean "this URL is, or should be, live". */
    private const LIVE_DEPLOYMENT_STATES = ['published', 'verification_pending', 'verified'];

    /** Content item states that mean the post is public. */
    private const LIVE_ITEM_STATES = ['published', 'live', 'verification_pending'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private ?CanonicalUrlPolicy $urlPolicy = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->urlPolicy = $urlPolicy ?? new CanonicalUrlPolicy();
    }

    /**
     * Register or refresh an identity for every live blog post.
     *
     * @return array{scanned:int, created:int, updated:int, skipped:int, without_url:int}
     */
    public function syncBlogs(int $tenantId = 1): array
    {
        $result = ['scanned' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'without_url' => 0];

        if (! SchemaGuard::hasTable($this->db, 'reach_content_identities') || ! SchemaGuard::hasTable($this->db, 'reach_content_items')) {
            return $result;
        }

        foreach ($this->liveBlogItems() as $item) {
            $result['scanned']++;

            $url = $this->canonicalUrlFor($item);
            if ($url === null) {
                $result['without_url']++;
                continue;
            }

            $outcome = $this->upsertIdentity($tenantId, 'blog', (int) $item['id'], [
                'canonical_url'      => $url,
                'publication_status' => 'published',
                'privacy_class'      => 'public',
                'analytics_eligible' => true,
                'public_site_route'  => parse_url($url, PHP_URL_PATH) ?: null,
                'last_published_at'  => $item['published_at'] ?: null,
                'content_version'    => $item['current_version_id'] !== null
                    ? (string) $item['current_version_id']
                    : null,
            ]);

            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * Normalised URL → identity id, for resolving Search Console page URLs in
     * bulk. Building the map once per ingestion run replaces a per-row query;
     * a single GSC page can otherwise mean tens of thousands of lookups.
     *
     * A post can legitimately be reachable at more than one URL, so every URL
     * that provably belongs to a specific content item is indexed against it:
     *
     *  - the identity's own canonical_url;
     *  - every canonical_url any deployment ever published it at;
     *  - the URL its *current* slug would produce.
     *
     * That last pair matters after a slug repair: the slug column is corrected
     * immediately but the post is only re-published later, so the live URL and
     * the current slug disagree for a while and Google reports whichever it
     * last crawled. Both are the same post, and both come from that item's own
     * records — this is not a wildcard match on a shared path segment, which is
     * why it cannot credit a marketing page's clicks to a blog.
     *
     * A URL claimed by two different identities is dropped rather than assigned
     * to whichever sorted first.
     *
     * @return array<string, int>
     */
    public function urlIndex(int $tenantId): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_content_identities')) {
            return [];
        }

        $identities = $this->db->table('reach_content_identities')
            ->select('id, source_id, content_type, canonical_url')
            ->where('tenant_id', $tenantId)
            ->get()->getResultArray();

        /** @var array<string, array<int, true>> $claims url => set of identity ids */
        $claims       = [];
        $blogBySource = [];

        foreach ($identities as $identity) {
            $id = (int) $identity['id'];

            $this->claim($claims, (string) ($identity['canonical_url'] ?? ''), $id);

            if (($identity['content_type'] ?? '') === 'blog') {
                $blogBySource[(int) $identity['source_id']] = $id;
            }
        }

        if ($blogBySource !== []) {
            foreach ($this->publishedUrlsBySource(array_keys($blogBySource)) as $sourceId => $urls) {
                foreach ($urls as $url) {
                    $this->claim($claims, $url, $blogBySource[$sourceId]);
                }
            }

            foreach ($this->slugUrlsBySource(array_keys($blogBySource)) as $sourceId => $url) {
                $this->claim($claims, $url, $blogBySource[$sourceId]);
            }
        }

        $index = [];
        foreach ($claims as $url => $owners) {
            if (count($owners) === 1) {
                $index[$url] = (int) array_key_first($owners);
            }
        }

        return $index;
    }

    /**
     * @param array<string, array<int, true>> $claims
     */
    private function claim(array &$claims, string $url, int $identityId): void
    {
        $key = self::normaliseUrl($url);
        if ($key !== '') {
            $claims[$key][$identityId] = true;
        }
    }

    /**
     * Every URL each content item has been published at, newest deployments
     * included — a post keeps drawing traffic on its previous URL while the
     * redirect is still being crawled.
     *
     * @param list<int> $sourceIds
     * @return array<int, list<string>>
     */
    private function publishedUrlsBySource(array $sourceIds): array
    {
        if ($sourceIds === [] || ! SchemaGuard::hasTable($this->db, 'reach_publication_deployments')) {
            return [];
        }

        $rows = $this->db->table('reach_publication_deployments')
            ->select('content_item_id, canonical_url')
            ->whereIn('content_item_id', $sourceIds)
            ->whereIn('status', self::LIVE_DEPLOYMENT_STATES)
            ->where('canonical_url IS NOT NULL', null, false)
            ->get()->getResultArray();

        $bySource = [];
        foreach ($rows as $row) {
            $bySource[(int) $row['content_item_id']][] = (string) $row['canonical_url'];
        }

        return $bySource;
    }

    /**
     * The URL each item's current slug would produce.
     *
     * @param list<int> $sourceIds
     * @return array<int, string>
     */
    private function slugUrlsBySource(array $sourceIds): array
    {
        if ($sourceIds === [] || ! SchemaGuard::hasTable($this->db, 'reach_content_items')) {
            return [];
        }

        $rows = $this->db->table('reach_content_items')
            ->select('id, slug')
            ->whereIn('id', $sourceIds)
            ->where('deleted_at IS NULL', null, false)
            ->get()->getResultArray();

        // Anchor the slug URL to the path the site is actually serving. Rebuilt
        // URLs would use CanonicalUrlPolicy's /blog/ prefix while live posts sit
        // under /blogs/, so the index would carry an entry Google can never
        // report and miss the one it does.
        $liveUrls = $this->publishedUrlsBySource($sourceIds);

        $urls = [];
        foreach ($rows as $row) {
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $id       = (int) $row['id'];
            $template = $liveUrls[$id][0] ?? null;

            $urls[$id] = $template !== null
                ? self::withLastSegment($template, $slug)
                : $this->urlPolicy->buildUrl('blog', $slug);
        }

        return $urls;
    }

    /**
     * Final path segment → identity id, for URLs whose *prefix* differs from
     * the one Reach generated.
     *
     * The public site 301s /blog/{slug} to /blogs/{slug}, and Search Console
     * reports the destination, so an identity built from CanonicalUrlPolicy
     * ("/blog/…") never matches the reported URL ("/blogs/…") on an exact
     * comparison. The slug survives the redirect, so it is the stable part.
     *
     * Ambiguous slugs are dropped rather than guessed: if two identities end in
     * the same segment there is no way to know which one the traffic belongs
     * to, and attributing it to either would be a fabrication.
     *
     * @return array<string, int>
     */
    public function slugIndex(int $tenantId): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_content_identities')) {
            return [];
        }

        $rows = $this->db->table('reach_content_identities')
            ->select('id, canonical_url')
            ->where('tenant_id', $tenantId)
            ->where('canonical_url IS NOT NULL', null, false)
            ->get()->getResultArray();

        $index  = [];
        $counts = [];
        foreach ($rows as $row) {
            $slug = self::slugOf((string) $row['canonical_url']);
            if ($slug === '') {
                continue;
            }
            $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            $index[$slug] ??= (int) $row['id'];
        }

        foreach ($counts as $slug => $count) {
            if ($count > 1) {
                unset($index[$slug]);
            }
        }

        return $index;
    }

    /**
     * The same URL with its final path segment replaced.
     *
     * Used to derive "where would this post be if its current slug were live"
     * from the URL the site is genuinely serving, so host, scheme and path
     * prefix stay identical and the slug is the only variable.
     */
    public static function withLastSegment(string $url, string $segment): string
    {
        $trimmed  = rtrim(trim($url), '/');
        $position = strrpos($trimmed, '/');

        return $position === false
            ? $trimmed . '/' . rawurlencode($segment)
            : substr($trimmed, 0, $position + 1) . rawurlencode($segment);
    }

    /**
     * The last path segment of a URL, lower-cased. Empty when the URL has no
     * path — a bare host must never match a post.
     */
    public static function slugOf(string $url): string
    {
        $normalised = self::normaliseUrl($url);
        $position   = strrpos($normalised, '/');

        return $position === false ? '' : strtolower(substr($normalised, $position + 1));
    }

    /**
     * Compare URLs the way Search Console and a CMS disagree about them:
     * scheme, a leading "www.", a trailing slash, query strings, fragments and
     * case in the host are all noise. The path's case is preserved because it
     * is genuinely significant on most servers.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        $path = (string) ($parts['path'] ?? '');
        $path = rtrim($path, '/');

        if ($host === '') {
            // A bare path ("/blog/post"); still comparable against other paths.
            return $path === '' ? '' : $path;
        }

        return $host . $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveBlogItems(): array
    {
        return $this->db->table('reach_content_items')
            ->select('id, slug, published_at, current_version_id, workflow_status')
            ->where('content_type', 'blog')
            ->whereIn('workflow_status', self::LIVE_ITEM_STATES)
            ->where('deleted_at IS NULL', null, false)
            ->get()->getResultArray();
    }

    /**
     * The URL the post is actually reachable at, or null when we genuinely
     * cannot tell.
     *
     * @param array<string, mixed> $item
     */
    private function canonicalUrlFor(array $item): ?string
    {
        if (SchemaGuard::hasTable($this->db, 'reach_publication_deployments')) {
            $deployed = $this->db->table('reach_publication_deployments')
                ->select('canonical_url')
                ->where('content_item_id', (int) $item['id'])
                ->whereIn('status', self::LIVE_DEPLOYMENT_STATES)
                ->where('canonical_url IS NOT NULL', null, false)
                ->orderBy('completed_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();

            $url = trim((string) ($deployed['canonical_url'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        $slug = trim((string) ($item['slug'] ?? ''));

        return $slug === '' ? null : $this->urlPolicy->buildUrl('blog', $slug);
    }

    /**
     * @param array<string, mixed> $data
     * @return 'created'|'updated'|'skipped'
     */
    private function upsertIdentity(int $tenantId, string $contentType, int $sourceId, array $data): string
    {
        $table    = $this->db->table('reach_content_identities');
        $existing = $this->db->table('reach_content_identities')
            ->where('tenant_id', $tenantId)
            ->where('content_type', $contentType)
            ->where('source_id', $sourceId)
            ->get()->getRowArray();

        $data = array_filter($data, static fn ($v) => $v !== null);

        if ($existing) {
            $changed = false;
            foreach ($data as $key => $value) {
                if (self::scalarise($existing[$key] ?? null) !== self::scalarise($value)) {
                    $changed = true;
                    break;
                }
            }
            if (! $changed) {
                return 'skipped';
            }

            $data['updated_at'] = date('Y-m-d H:i:s');
            $table->where('id', (int) $existing['id'])->update($data);

            return 'updated';
        }

        $table->insert($data + [
            'tenant_id'    => $tenantId,
            'content_type' => $contentType,
            'source_id'    => $sourceId,
        ]);

        return 'created';
    }

    /**
     * Compare a stored value against a candidate without booleans reading as a
     * change every run: Postgres hands back 't'/'f' (or true/false depending on
     * driver settings) where the candidate is a PHP bool, and a naive string
     * cast makes 'true' !== 't' forever.
     */
    private static function scalarise(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (in_array($value, ['t', 'true', 'TRUE'], true)) {
            return '1';
        }
        if (in_array($value, ['f', 'false', 'FALSE'], true)) {
            return '0';
        }

        return trim((string) $value);
    }
}
