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
     * @return array<string, int>
     */
    public function urlIndex(int $tenantId): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_content_identities')) {
            return [];
        }

        $rows = $this->db->table('reach_content_identities')
            ->select('id, canonical_url')
            ->where('tenant_id', $tenantId)
            ->where('canonical_url IS NOT NULL', null, false)
            ->get()->getResultArray();

        $index = [];
        foreach ($rows as $row) {
            $key = self::normaliseUrl((string) $row['canonical_url']);
            if ($key !== '' && ! isset($index[$key])) {
                $index[$key] = (int) $row['id'];
            }
        }

        return $index;
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
