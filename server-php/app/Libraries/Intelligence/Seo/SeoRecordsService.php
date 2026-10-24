<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Seo;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The records behind the SEO Command Centre counts: one backlink snapshot
 * and the rows it summarised, IndexNow submissions (list + attempts) and
 * blog posts by indexing status. Each list applies the same predicate the
 * count used, so a number and the list it opens agree.
 */
final class SeoRecordsService
{
    public const INDEXNOW_STATUSES     = ['pending', 'submitted', 'failed', 'retrying', 'cancelled'];
    public const INDEXING_STATUSES     = ['not_submitted', 'sitemap_included', 'discovered', 'crawled', 'indexed', 'excluded', 'error', 'unknown'];
    public const INTERNAL_LINK_STATUSES = ['draft', 'active', 'broken', 'removed'];
    public const LINK_TARGET_TYPES     = ['internal', 'external', 'anchor'];

    /** Items SeoSnapshotService::captureBacklinkSnapshot() counts as published URLs. */
    private const PUBLISHED_ITEM_STATES = ['published', 'live'];

    /** Deployment states whose canonical URL is live (as ContentIdentitySyncService). */
    private const LIVE_DEPLOYMENT_STATES = ['published', 'verification_pending', 'verified'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    // ── Backlinks ───────────────────────────────────────────────────────────

    /**
     * One backlink snapshot: its totals, everything in details_json (lists
     * such as referring domains or links become rows), and the records its
     * two internal counts summarise.
     *
     * internal_links and published_urls are counted from live tables, so the
     * rows are today's — `current_count` next to the snapshot's figure shows
     * any drift since the snapshot was taken.
     *
     * @param array<string, mixed> $f kind=internal_links|published_urls, status, target_type, q
     * @return array<string, mixed>|null
     */
    public function backlinkSnapshot(int $id, array $f = [], int $page = 1, int $perPage = 50): ?array
    {
        $snap = $this->db->table('reach_backlink_snapshots')->where('id', $id)->get()->getRowArray();
        if (! $snap) {
            return null;
        }
        $details = is_array($snap['details_json']) ? $snap['details_json'] : json_decode((string) $snap['details_json'], true);
        $details = is_array($details) ? $details : [];
        unset($snap['details_json']);

        // Scalars are shown as facts; lists (referring_domains, links, …) as tables.
        $facts = [];
        $lists = [];
        foreach ($details as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $lists[] = ['key' => (string) $key, 'rows' => array_map(static fn ($v) => is_array($v) ? $v : ['value' => $v], $value)];
            } elseif (is_array($value)) {
                $facts[(string) $key] = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $facts[(string) $key] = $value;
            }
        }

        $kind    = ($f['kind'] ?? '') === 'published_urls' ? 'published_urls' : 'internal_links';
        $records = $kind === 'published_urls'
            ? $this->publishedUrls($f, $page, $perPage)
            : $this->internalLinks($f, $page, $perPage);

        return [
            'snapshot'      => $snap,
            'detail_facts'  => $facts,
            'detail_lists'  => $lists,
            'kind'          => $kind,
            'current_count' => [
                'internal_links' => $this->countInternalLinks(),
                'published_urls' => $this->countPublishedUrls(),
            ],
            'records'       => $records,
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>} */
    private function internalLinks(array $f, int $page, int $perPage): array
    {
        [$page, $perPage] = self::paging($page, $perPage);
        if (! SchemaGuard::hasTable($this->db, 'reach_content_internal_links')) {
            return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'filters' => []];
        }
        $where = ['1 = 1'];
        $binds = [];
        $clean = [];
        if (in_array($f['status'] ?? null, self::INTERNAL_LINK_STATUSES, true)) {
            $where[] = 'l.status = ?';
            $binds[] = $clean['status'] = $f['status'];
        }
        if (in_array($f['target_type'] ?? null, self::LINK_TARGET_TYPES, true)) {
            $where[] = 'l.target_type = ?';
            $binds[] = $clean['target_type'] = $f['target_type'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(l.target_public_url ILIKE ? OR l.anchor_text ILIKE ? OR s.title ILIKE ?)';
            $like    = self::like($q);
            array_push($binds, $like, $like, $like);
            $clean['q'] = $q;
        }
        $w = implode(' AND ', $where);

        $from  = 'FROM reach_content_internal_links l
                  LEFT JOIN reach_content_items s ON s.id = l.source_content_item_id
                  LEFT JOIN reach_content_items t ON t.id = l.target_content_item_id';
        $total = (int) ($this->db->query("SELECT COUNT(*) AS n {$from} WHERE {$w}", $binds)->getRowArray()['n'] ?? 0);
        $rows  = $this->db->query(
            "SELECT l.id, l.source_content_item_id, s.title AS source_title, l.target_type, l.target_content_item_id,
                    t.title AS target_title, l.target_public_url, l.anchor_text, l.status, l.validation_status, l.last_checked_at, l.created_at
               {$from} WHERE {$w}
              ORDER BY l.id DESC LIMIT ? OFFSET ?",
            array_merge($binds, [$perPage, ($page - 1) * $perPage])
        )->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'filters' => $clean];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>} */
    private function publishedUrls(array $f, int $page, int $perPage): array
    {
        return $this->blogPosts(['published_only' => true, 'q' => $f['q'] ?? null], $page, $perPage);
    }

    private function countInternalLinks(): ?int
    {
        try {
            return (int) $this->db->table('reach_content_internal_links')->countAllResults();
        } catch (\Throwable) {
            return null;
        }
    }

    private function countPublishedUrls(): ?int
    {
        try {
            return (int) $this->db->table('reach_blog_publication_profiles p')
                ->join('reach_content_items i', 'i.id = p.content_item_id')
                ->whereIn('i.workflow_status', self::PUBLISHED_ITEM_STATES)
                ->countAllResults();
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Blog indexing status ────────────────────────────────────────────────

    /**
     * Blog posts (publication profiles) by indexing status — the rows the
     * overview's "Blog indexing status" counts group.
     *
     * @param array<string, mixed> $f indexing_status, q, published_only
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>, counts: array<string, int>}
     */
    public function blogPosts(array $f, int $page = 1, int $perPage = 50): array
    {
        [$page, $perPage] = self::paging($page, $perPage);
        $where = ['1 = 1'];
        $binds = [];
        $clean = [];
        if (in_array($f['indexing_status'] ?? null, self::INDEXING_STATUSES, true)) {
            $where[] = 'p.indexing_status = ?';
            $binds[] = $clean['indexing_status'] = $f['indexing_status'];
        }
        if (! empty($f['published_only'])) {
            $where[] = 'i.workflow_status IN (' . implode(',', array_fill(0, count(self::PUBLISHED_ITEM_STATES), '?')) . ')';
            array_push($binds, ...self::PUBLISHED_ITEM_STATES);
            $clean['published_only'] = 1;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(i.title ILIKE ? OR i.slug ILIKE ?)';
            $like    = self::like($q);
            array_push($binds, $like, $like);
            $clean['q'] = $q;
        }
        $w = implode(' AND ', $where);

        // The overview groups every profile, so the list joins the item
        // with LEFT JOIN semantics only where the count did not filter on it.
        $join  = ! empty($f['published_only']) || $q !== '' ? 'JOIN' : 'LEFT JOIN';
        $from  = "FROM reach_blog_publication_profiles p {$join} reach_content_items i ON i.id = p.content_item_id";
        $total = (int) ($this->db->query("SELECT COUNT(*) AS n {$from} WHERE {$w}", $binds)->getRowArray()['n'] ?? 0);

        $live = implode(',', array_fill(0, count(self::LIVE_DEPLOYMENT_STATES), '?'));
        $urlSql = SchemaGuard::hasTable($this->db, 'reach_publication_deployments')
            ? "(SELECT d.canonical_url FROM reach_publication_deployments d
                 WHERE d.content_item_id = p.content_item_id AND d.status IN ({$live}) AND d.canonical_url IS NOT NULL
                 ORDER BY d.id DESC LIMIT 1)"
            : 'NULL';
        $urlBinds = $urlSql === 'NULL' ? [] : self::LIVE_DEPLOYMENT_STATES;

        $rows = $this->db->query(
            "SELECT p.id AS profile_id, p.content_item_id, i.title, i.slug, i.workflow_status, i.published_at,
                    p.indexing_status, p.indexing_checked_at, p.category, {$urlSql} AS public_url
               {$from} WHERE {$w}
              ORDER BY p.indexing_checked_at DESC NULLS LAST, p.id DESC
              LIMIT ? OFFSET ?",
            array_merge($urlBinds, $binds, [$perPage, ($page - 1) * $perPage])
        )->getResultArray();

        $counts = [];
        foreach ($this->db->query('SELECT indexing_status, COUNT(*) AS c FROM reach_blog_publication_profiles GROUP BY indexing_status')->getResultArray() as $c) {
            $counts[(string) $c['indexing_status']] = (int) $c['c'];
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'filters' => $clean, 'counts' => $counts];
    }

    // ── IndexNow ────────────────────────────────────────────────────────────

    /**
     * Submissions, newest first, with counts per status (over the same url /
     * content filter, not the status filter, so the tiles stay stable).
     *
     * @param array<string, mixed> $f status (one or comma list), url (contains), content_identity_id
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>, counts: array<string, int>}
     */
    public function indexnowSubmissions(array $f, int $page = 1, int $perPage = 50): array
    {
        [$page, $perPage] = self::paging($page, $perPage);
        $base  = ['1 = 1'];
        $binds = [];
        $clean = [];
        $url   = trim((string) ($f['url'] ?? ''));
        if ($url !== '') {
            $base[]  = 's.url ILIKE ?';
            $binds[] = self::like($url);
            $clean['url'] = $url;
        }
        if (($cid = (int) ($f['content_identity_id'] ?? 0)) > 0) {
            $base[]  = 's.content_identity_id = ?';
            $binds[] = $clean['content_identity_id'] = $cid;
        }

        $statuses = array_values(array_intersect(
            array_map('trim', explode(',', (string) ($f['status'] ?? ''))),
            self::INDEXNOW_STATUSES
        ));
        $where     = $base;
        $listBinds = $binds;
        if ($statuses !== []) {
            $where[] = 's.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($listBinds, ...$statuses);
            $clean['status'] = implode(',', $statuses);
        }
        $w = implode(' AND ', $where);

        $total = (int) ($this->db->query("SELECT COUNT(*) AS n FROM reach_indexnow_submissions s WHERE {$w}", $listBinds)->getRowArray()['n'] ?? 0);
        $rows  = $this->db->query(
            "SELECT s.id, s.uuid, s.content_identity_id, s.url, s.provider_endpoint, s.status, s.attempt_count, s.max_attempts,
                    s.submitted_at, s.next_retry_at, s.completed_at, s.triggered_by, s.created_at,
                    (SELECT a.error_message FROM reach_indexnow_attempts a WHERE a.submission_id = s.id ORDER BY a.attempt_number DESC LIMIT 1) AS last_error,
                    (SELECT a.http_status FROM reach_indexnow_attempts a WHERE a.submission_id = s.id ORDER BY a.attempt_number DESC LIMIT 1) AS last_http_status
               FROM reach_indexnow_submissions s
              WHERE {$w}
              ORDER BY s.id DESC LIMIT ? OFFSET ?",
            array_merge($listBinds, [$perPage, ($page - 1) * $perPage])
        )->getResultArray();

        $counts = array_fill_keys(self::INDEXNOW_STATUSES, 0);
        foreach ($this->db->query('SELECT s.status, COUNT(*) AS c FROM reach_indexnow_submissions s WHERE ' . implode(' AND ', $base) . ' GROUP BY s.status', $binds)->getResultArray() as $c) {
            $counts[(string) $c['status']] = (int) $c['c'];
        }
        $counts['total'] = array_sum($counts);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'filters' => $clean, 'counts' => $counts];
    }

    /**
     * One submission with every attempt (HTTP status, provider response,
     * error, latency) and the content it was for.
     *
     * @return array<string, mixed>|null
     */
    public function indexnowSubmission(int $id): ?array
    {
        $row = $this->db->table('reach_indexnow_submissions')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return null;
        }
        $row['attempts'] = $this->db->table('reach_indexnow_attempts')
            ->where('submission_id', $id)->orderBy('attempt_number')->get()->getResultArray();

        $row['content'] = null;
        if (! empty($row['content_identity_id']) && SchemaGuard::hasTable($this->db, 'reach_content_identities')) {
            $ci = $this->db->table('reach_content_identities')
                ->select('id, content_type, source_id, canonical_url, publication_status')
                ->where('id', (int) $row['content_identity_id'])->get()->getRowArray();
            if ($ci) {
                $ci['content_item_id'] = $ci['content_type'] === 'blog' ? (int) $ci['source_id'] : null;
                $row['content']        = $ci;
            }
        }

        return $row;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int} */
    private static function paging(int $page, int $perPage): array
    {
        return [max(1, $page), max(1, min(200, $perPage))];
    }

    private static function like(string $q): string
    {
        return '%' . addcslashes(mb_substr($q, 0, 200), '%_\\') . '%';
    }
}
