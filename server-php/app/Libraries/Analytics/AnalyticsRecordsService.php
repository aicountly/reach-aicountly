<?php

declare(strict_types=1);

namespace App\Libraries\Analytics;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The records behind the Analytics page's internal tiles that have no list
 * page of their own: legacy blog posts (reach_blog_posts, still written by
 * the bot's "Generate blog draft") and imported engagement snapshots
 * (reach_analytics_snapshots). Each tile count and its list share one
 * predicate here, so the number and the rows it opens always agree.
 */
final class AnalyticsRecordsService
{
    /** Tile buckets over reach_blog_posts (AnalyticsController::summary). */
    public const BLOG_BUCKETS = ['drafts', 'published', 'approval_pending'];

    private const BLOG_DRAFT_STATUSES = ['draft', 'seo_review', 'internal_review'];

    /** BlogController::WORKFLOW. */
    public const BLOG_STATUSES = [
        'idea', 'draft', 'seo_review', 'internal_review',
        'approved', 'scheduled', 'published', 'rejected', 'archived',
    ];

    public const BLOG_APPROVAL_STATUSES = ['pending', 'approved', 'rejected', 'not_required'];

    /** reach_analytics_source_check. `external` = everything but internal (the tile). */
    public const SNAPSHOT_SOURCES = ['internal', 'ga4', 'gsc', 'meta', 'linkedin', 'twitter', 'youtube', 'email', 'whatsapp'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    // ------------------------------------------------------------- legacy blog

    public function legacyBlogCount(string $bucket, int $days = 30): int
    {
        $q = $this->db->table('reach_blog_posts');
        $this->applyBlogFilters($q, ['bucket' => $bucket, 'days' => $days]);

        return $q->countAllResults();
    }

    /**
     * @param array<string, mixed> $filters bucket, status, approval_status, days, q
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int, counts: array<string, int>, days: int}
     */
    public function legacyBlogPosts(array $filters, int $page = 1, int $limit = 25): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(100, $limit));
        $days  = self::days($filters['days'] ?? null);

        $hasContentItem = SchemaGuard::hasColumn($this->db, 'reach_blog_posts', 'content_item_id');
        $select = 'id, title, slug, excerpt, content, status, approval_status, publishing_status, publishing_error, '
            . 'bot_generated, focus_keyword, canonical_url, external_post_id, created_at, updated_at, published_at, approved_at'
            . ($hasContentItem ? ', content_item_id' : '');

        $q = $this->db->table('reach_blog_posts')->select($select);
        $this->applyBlogFilters($q, $filters + ['days' => $days]);
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('updated_at', 'DESC')->orderBy('id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)->get()->getResultArray();

        $counts = [];
        foreach (self::BLOG_BUCKETS as $bucket) {
            $counts[$bucket] = $this->legacyBlogCount($bucket, $days);
        }

        return [
            'items'  => array_map(static function (array $r) use ($hasContentItem): array {
                $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($r['content'] ?? ''))));
                unset($r['content']);

                return array_merge($r, [
                    'id'              => (int) $r['id'],
                    'bot_generated'   => in_array($r['bot_generated'] ?? null, [true, 't', 'true', 1, '1'], true),
                    'content_item_id' => $hasContentItem && $r['content_item_id'] !== null ? (int) $r['content_item_id'] : null,
                    'content_preview' => mb_substr($text, 0, 600),
                    'content_length'  => mb_strlen($text),
                ]);
            }, $rows),
            'total'  => $total,
            'page'   => $page,
            'limit'  => $limit,
            'counts' => $counts,
            'days'   => $days,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyBlogFilters(BaseBuilder $q, array $filters): void
    {
        $days = self::days($filters['days'] ?? null);

        switch ((string) ($filters['bucket'] ?? '')) {
            case 'drafts':
                $q->whereIn('status', self::BLOG_DRAFT_STATUSES);
                break;
            case 'published':
                $q->where('status', 'published')->where('published_at >=', self::since($days));
                break;
            case 'approval_pending':
                $q->where('approval_status', 'pending');
                break;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::BLOG_STATUSES, true)) {
            $q->where('status', $status);
        }
        $approval = (string) ($filters['approval_status'] ?? '');
        if (in_array($approval, self::BLOG_APPROVAL_STATUSES, true)) {
            $q->where('approval_status', $approval);
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $q->groupStart()
                ->like('title', $search, 'both', true, true)
                ->orLike('slug', $search, 'both', true, true)
                ->groupEnd();
        }
    }

    // ---------------------------------------------------------------- snapshots

    public function snapshotCount(string $source, int $days = 30): int
    {
        $q = $this->db->table('reach_analytics_snapshots');
        $this->applySnapshotFilters($q, ['source' => $source, 'days' => $days]);

        return $q->countAllResults();
    }

    /**
     * @param array<string, mixed> $filters source (a source or `external`), days, from, to (Y-m-d), id
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int, by_source: array<string, int>}
     */
    public function snapshots(array $filters, int $page = 1, int $limit = 25): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(100, $limit));

        $q = $this->db->table('reach_analytics_snapshots')->select('id, source, captured_at, metrics, created_at');
        $this->applySnapshotFilters($q, $filters);
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('captured_at', 'DESC')->orderBy('id', 'DESC')
            ->limit($limit, ($page - 1) * $limit)->get()->getResultArray();

        // Per-source counts in the same window (any source), for the facet chips.
        $facet = $this->db->table('reach_analytics_snapshots')->select('source, COUNT(*) AS n')->groupBy('source');
        $this->applySnapshotFilters($facet, array_diff_key($filters, ['source' => true, 'id' => true]));
        $bySource = [];
        foreach ($facet->get()->getResultArray() as $r) {
            $bySource[(string) $r['source']] = (int) $r['n'];
        }
        arsort($bySource);

        return [
            'items'     => array_map(static function (array $r): array {
                $metrics = json_decode((string) ($r['metrics'] ?? 'null'), true);

                return [
                    'id'          => (int) $r['id'],
                    'source'      => (string) $r['source'],
                    'captured_at' => $r['captured_at'],
                    'created_at'  => $r['created_at'],
                    'metrics'     => $metrics,
                ];
            }, $rows),
            'total'     => $total,
            'page'      => $page,
            'limit'     => $limit,
            'by_source' => $bySource,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applySnapshotFilters(BaseBuilder $q, array $filters): void
    {
        $id = (int) ($filters['id'] ?? 0);
        if ($id > 0) {
            $q->where('id', $id);
        }

        $source = (string) ($filters['source'] ?? '');
        if ($source === 'external') {
            $q->where('source !=', 'internal');
        } elseif (in_array($source, self::SNAPSHOT_SOURCES, true)) {
            $q->where('source', $source);
        }

        $from = self::date($filters['from'] ?? null);
        $to   = self::date($filters['to'] ?? null);
        if ($from !== null) {
            $q->where('captured_at >=', $from . ' 00:00:00');
        }
        if ($to !== null) {
            $q->where('captured_at <', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00');
        }
        if ($from === null && $to === null && ($filters['days'] ?? null) !== null && (string) $filters['days'] !== '') {
            $q->where('captured_at >=', self::since(self::days($filters['days'])));
        }
    }

    // ------------------------------------------------------------------ helpers

    /** The summary's window: the same `-N days` timestamp AnalyticsController used. */
    private static function since(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    private static function days(mixed $value): int
    {
        $days = (int) ($value ?? 30);

        return $days > 0 ? min(3650, $days) : 30;
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
