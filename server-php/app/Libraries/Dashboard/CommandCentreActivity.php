<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard;

use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * "What the bot did": the counters on the command centre and the drill-down
 * behind each of them (`GET v1/dashboard/command-centre/activity`).
 *
 * One rule keeps the two honest: a counter and its list are built from the
 * same scope() — the same FROM, the same filter, the same rolling window
 * (24 / 168 / 720 hours back from one database NOW()). A tile reading 58
 * opens a list of exactly 58 rows, every one reachable by paging.
 *
 * Failed jobs are listed grouped by job type (count, first and latest
 * failure, latest error, a Job Monitor link with the same filter), because
 * thousands of rows of the same historic failure tell the owner nothing.
 */
final class CommandCentreActivity
{
    /** Rolling windows, in hours. Accepts the API names and the UI's ids. */
    public const WINDOWS = ['24h' => 24, '7d' => 168, '30d' => 720];

    private const WINDOW_ALIASES = ['h24' => '24h', 'd7' => '7d', 'd30' => '30d'];

    private const NEGATIVE = ['vetting_rejected', 'job_failed'];

    private BaseConnection $db;

    /** @var array<string, array<string, string>>|null */
    private ?array $sources = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** '24h' | '7d' | '30d' (or h24 / d7 / d30) → canonical name; null when unknown. */
    public static function window(string $window): ?string
    {
        $w = self::WINDOW_ALIASES[$window] ?? $window;

        return isset(self::WINDOWS[$w]) ? $w : null;
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_keys($this->sources());
    }

    /**
     * Per-kind counts for every window, as of one database NOW().
     *
     * @return array<string, array{label:string,h24:int,d7:int,d30:int}>
     */
    public function counters(?string $asOf = null): array
    {
        $asOf ??= $this->asOf();
        $out    = [];
        foreach ($this->sources() as $kind => $src) {
            [$scope, $binds] = $this->scope($src, self::WINDOWS['30d'], $asOf);
            $c = $this->db->query(
                "SELECT COUNT(*) FILTER (WHERE {$src['at']} >= ?::timestamptz - make_interval(hours => 24))  AS h24,
                        COUNT(*) FILTER (WHERE {$src['at']} >= ?::timestamptz - make_interval(hours => 168)) AS d7,
                        COUNT(*) AS d30
                   {$scope}",
                [$asOf, $asOf, ...$binds]
            )->getRowArray();
            $out[$kind] = ['label' => $src['label'], 'h24' => (int) $c['h24'], 'd7' => (int) $c['d7'], 'd30' => (int) $c['d30']];
        }

        return $out;
    }

    /**
     * The items behind one counter (or "all" when $kind is ''), newest first.
     *
     * @return array<string, mixed>
     */
    public function drilldown(string $kind, string $window, int $page = 1, int $perPage = 25): array
    {
        $window  = self::window($window) ?? throw new \InvalidArgumentException('Unknown window: use 24h, 7d or 30d.');
        if ($kind !== '' && ! isset($this->sources()[$kind])) {
            throw new \InvalidArgumentException('Unknown activity kind: ' . $kind);
        }
        $hours   = self::WINDOWS[$window];
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $asOf    = $this->asOf();

        $counters = $this->counters($asOf);
        $key      = ['24h' => 'h24', '7d' => 'd7', '30d' => 'd30'][$window];
        $total    = $kind === '' ? array_sum(array_column($counters, $key)) : $counters[$kind][$key];

        $out = [
            'kind'         => $kind === '' ? 'all' : $kind,
            'label'        => $kind === '' ? 'All' : $this->sources()[$kind]['label'],
            'window'       => $window,
            'window_hours' => $hours,
            'as_of'        => self::iso($asOf),
            'since'        => self::iso($this->db->query('SELECT ?::timestamptz - make_interval(hours => ' . $hours . ') AS s', [$asOf])->getRow()->s),
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'grouped'      => $kind === 'job_failed',
            'counters'     => $counters,
        ];

        if ($kind === 'job_failed') {
            [$scope, $binds] = $this->scope($this->sources()['job_failed'], $hours, $asOf);
            $groups = (int) $this->db->query("SELECT COUNT(DISTINCT j.job_type) AS n {$scope}", $binds)->getRow()->n;
            $out['groups_total'] = $groups;
            $out['items']        = $this->jobGroups($hours, $asOf, $perPage, $offset);
            $out['has_more']     = $offset + count($out['items']) < $groups;

            return $out;
        }

        $out['items']    = $this->items($kind, $hours, $perPage, $offset, $asOf);
        $out['has_more'] = $offset + count($out['items']) < $total;

        return $out;
    }

    /**
     * Individual items, newest first: one kind, or every kind when $kind is ''.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $kind, int $hours, int $limit, int $offset = 0, ?string $asOf = null): array
    {
        $asOf ??= $this->asOf();
        $parts  = [];
        $binds  = [];
        foreach ($this->sources() as $k => $src) {
            if ($kind !== '' && $k !== $kind) {
                continue;
            }
            [$scope, $b] = $this->scope($src, $hours, $asOf);
            $parts[]     = "SELECT '{$k}'::text AS kind, ({$src['id']})::bigint AS id, ({$src['title']})::text AS title,
                                   ({$src['detail']})::text AS detail, ({$src['link']})::text AS link,
                                   ({$src['public_url']})::text AS public_url, ({$src['extra']})::text AS extra,
                                   {$src['at']} AS at
                              {$scope}";
            array_push($binds, ...$b);
        }
        if ($parts === []) {
            return [];
        }

        $rows = $this->db->query(
            implode("\nUNION ALL\n", $parts) . "\nORDER BY at DESC, kind, id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $binds
        )->getResultArray();

        $items = array_map(fn (array $r): array => $this->item($r), $rows);

        return $this->resolveVettingTitles($items);
    }

    // ───────────────────────────────────────────────────────────── internals

    /**
     * The shared FROM + WHERE of a counter and its list.
     *
     * @param array<string, string> $src
     * @return array{0:string, 1:list<string>}
     */
    private function scope(array $src, int $hours, string $asOf): array
    {
        return [
            "FROM {$src['from']} WHERE ({$src['where']})
               AND {$src['at']} >= ?::timestamptz - make_interval(hours => {$hours})
               AND {$src['at']} <= ?::timestamptz",
            [$asOf, $asOf],
        ];
    }

    private function asOf(): string
    {
        return (string) $this->db->query('SELECT NOW() AS n')->getRow()->n;
    }

    /** @return list<array<string, mixed>> */
    private function jobGroups(int $hours, string $asOf, int $limit, int $offset): array
    {
        $src             = $this->sources()['job_failed'];
        [$scope, $binds] = $this->scope($src, $hours, $asOf);
        $at              = $src['at'];
        $rows            = $this->db->query(
            "SELECT j.job_type, COUNT(*) AS n, MAX({$at}) AS latest_at, MIN({$at}) AS first_at,
                    COUNT(*) FILTER (WHERE {$at} >= ?::timestamptz - make_interval(hours => 24)) AS last_24h,
                    (ARRAY_AGG(j.error_message ORDER BY {$at} DESC, j.id DESC))[1] AS latest_error,
                    (ARRAY_AGG(j.id ORDER BY {$at} DESC, j.id DESC))[1] AS latest_id
               {$scope}
              GROUP BY j.job_type
              ORDER BY latest_at DESC, j.job_type
              LIMIT {$limit} OFFSET {$offset}",
            [$asOf, ...$binds]
        )->getResultArray();

        return array_map(static fn (array $r): array => [
            'kind'         => 'job_failed',
            'job_type'     => (string) $r['job_type'],
            'count'        => (int) $r['n'],
            'last_24h'     => (int) $r['last_24h'],
            'first_at'     => self::iso($r['first_at']),
            'latest_at'    => self::iso($r['latest_at']),
            'latest_error' => $r['latest_error'] !== null && $r['latest_error'] !== '' ? mb_substr((string) $r['latest_error'], 0, 400) : null,
            'latest_link'  => '/admin/jobs/' . (int) $r['latest_id'],
            // The Job Monitor applies these through JobQueueInsights::applyFilters():
            // the same statuses, job type and COALESCE(completed_at, updated_at) window.
            'link'         => '/admin/jobs?status=dead_letter,failed&job_type=' . rawurlencode((string) $r['job_type']) . '&since_hours=' . $hours,
            'tone'         => 'negative',
        ], $rows);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function item(array $r): array
    {
        $kind   = (string) $r['kind'];
        $title  = trim((string) ($r['title'] ?? ''));
        $detail = trim((string) ($r['detail'] ?? ''));
        $link   = is_string($r['link']) && str_starts_with($r['link'], '/') ? $r['link'] : null;
        $public = is_string($r['public_url']) && preg_match('#^https?://#i', $r['public_url']) === 1 ? $r['public_url'] : null;

        $item = [
            'kind'       => $kind,
            'id'         => (int) $r['id'],
            'label'      => $this->sources()[$kind]['label'],
            'title'      => $title !== '' ? $title : '(untitled)',
            'detail'     => $detail !== '' ? mb_substr($detail, 0, 400) : null,
            'at'         => self::iso($r['at']),
            'link'       => $link,
            'public_url' => $public,
            'tone'       => in_array($kind, self::NEGATIVE, true) ? 'negative' : 'positive',
        ];

        if ($kind === 'vetting_rejected') {
            $extra   = json_decode((string) ($r['extra'] ?? ''), true) ?: [];
            $reasons = is_array($extra['reasons'] ?? null) ? $extra['reasons'] : [];
            $reasons = array_values(array_filter(array_map(
                static fn ($x): string => is_string($x) ? trim($x) : trim((string) json_encode($x, JSON_UNESCAPED_UNICODE)),
                $reasons
            ), static fn (string $x): bool => $x !== ''));
            $item['content_type'] = (string) ($extra['content_type'] ?? '');
            $item['ref']          = isset($extra['ref']) && $extra['ref'] !== '' ? (string) $extra['ref'] : null;
            $item['model']        = isset($extra['model']) ? (string) $extra['model'] : null;
            $item['draft_title']  = isset($extra['draft_title']) && trim((string) $extra['draft_title']) !== '' ? trim((string) $extra['draft_title']) : null;
            $item['reasons']      = array_map(static fn (string $x): string => mb_substr($x, 0, 600), $reasons);
            if ($item['draft_title'] !== null) {
                $item['title'] = $item['draft_title'];
            }
            $item['detail']       = $reasons === [] ? null : mb_substr(implode(' · ', $reasons), 0, 400);
        }

        return $item;
    }

    /**
     * A vetting run only stores its content_ref ("blog:12", "video:<uuid>",
     * "community_submission:7" …). Name the thing it judged and link to it.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function resolveVettingTitles(array $items): array
    {
        $want = [];
        foreach ($items as $i => $it) {
            if ($it['kind'] !== 'vetting_rejected' || $it['ref'] === null) {
                continue;
            }
            $parts = explode(':', (string) $it['ref']);
            $type  = $parts[0];
            $id    = $parts[1] ?? '';
            if ($id === '') {
                continue;
            }
            $want[$type][$id][] = $i;
        }

        $lookups = [
            // ref type => [table, key column, title SQL, item link SQL, list page when the row is gone]
            'blog'                 => ['reach_content_items', 'id', 'title', "'/content/' || id", null],
            'knowledge_base'       => ['reach_content_items', 'id', 'title', "'/content/' || id", null],
            'community_submission' => ['reach_community_automation_submissions', 'id', 'question_title', "'/community/automated'", '/community/automated'],
            'community_question'   => ['reach_community_questions', 'id', 'title', "'/community/questions/' || uuid", '/community/questions'],
            'community_answer'     => ['reach_community_official_answers', 'id', '(SELECT q.title FROM reach_community_questions q WHERE q.id = question_id)', "'/community/answers/' || uuid", '/community/answers'],
            'video'                => ['reach_video_pipeline_items', 'uuid', 'title', "'/video'", '/video'],
            'social_submission'    => ['reach_social_posts', 'uuid', "COALESCE(NULLIF(title, ''), LEFT(content, 90))", "'/social/queue'", '/social/queue'],
            'social_inbox'         => [null, null, null, null, '/social/inbox'],
            'release_note'         => ['reach_structured_content_submissions', 'external_key', 'title', "CASE WHEN content_item_id IS NOT NULL THEN '/content/' || content_item_id END", null],
            'workflow_guide'       => ['reach_structured_content_submissions', 'external_key', 'title', "CASE WHEN content_item_id IS NOT NULL THEN '/content/' || content_item_id END", null],
        ];

        foreach ($want as $type => $byId) {
            if (! isset($lookups[$type])) {
                continue;
            }
            [$table, $col, $titleSql, $linkSql, $fallback] = $lookups[$type];
            $found = [];
            if ($table !== null && SchemaGuard::hasTable($this->db, $table)) {
                $ids = array_keys($byId);
                if ($col === 'id') {
                    $ids = array_values(array_filter($ids, static fn ($x): bool => ctype_digit((string) $x)));
                } elseif ($col === 'uuid') {
                    $ids = array_values(array_filter($ids, static fn ($x): bool => preg_match('/^[0-9a-f-]{36}$/i', (string) $x) === 1));
                }
                if ($ids !== []) {
                    $marks = implode(',', array_fill(0, count($ids), '?'));
                    $extra = $table === 'reach_structured_content_submissions' ? ' AND content_type = ' . $this->db->escape($type) : '';
                    try {
                        foreach ($this->db->query(
                            "SELECT {$col}::text AS k, ({$titleSql})::text AS t, ({$linkSql})::text AS l FROM {$table} WHERE {$col}::text IN ({$marks}){$extra}",
                            array_map('strval', $ids)
                        )->getResultArray() as $row) {
                            $found[$row['k']] = $row;
                        }
                    } catch (\Throwable $e) {
                        log_message('warning', 'CommandCentreActivity vetting ref lookup ' . $type . ': ' . $e->getMessage());
                    }
                }
            }
            foreach ($byId as $id => $indexes) {
                $row = $found[(string) $id] ?? null;
                foreach ($indexes as $i) {
                    $name = trim((string) ($row['t'] ?? ''));
                    if ($name !== '') {
                        $items[$i]['title'] = $name;
                    }
                    $items[$i]['link'] = $row !== null && is_string($row['l']) && str_starts_with($row['l'], '/') ? $row['l'] : $fallback;
                }
            }
        }

        return $items;
    }

    private static function iso(mixed $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $ts))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return (string) $ts;
        }
    }

    /**
     * kind => from (with alias), at, where, and the item's id / title /
     * detail / internal link / public URL / extra (JSON text) expressions.
     * Only sources whose table exists are returned.
     *
     * @return array<string, array<string, string>>
     */
    private function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $deployUrl = SchemaGuard::hasTable($this->db, 'reach_publication_deployments')
            ? '(SELECT d.canonical_url FROM reach_publication_deployments d WHERE d.content_item_id = ci.id AND d.canonical_url IS NOT NULL ORDER BY d.completed_at DESC NULLS LAST, d.id DESC LIMIT 1)'
            : 'NULL';
        $published = "ci.workflow_status IN ('published','live','verification_pending') AND ci.deleted_at IS NULL AND ci.published_at IS NOT NULL";

        $all = [
            'blog_published' => [
                'table' => 'reach_content_items', 'from' => 'reach_content_items ci', 'at' => 'ci.published_at',
                'where' => "ci.content_type = 'blog' AND {$published}",
                'id' => 'ci.id', 'title' => 'ci.title', 'detail' => 'NULL', 'link' => "'/content/' || ci.id", 'public_url' => $deployUrl, 'extra' => 'NULL',
                'label' => 'Blog published',
            ],
            'kb_published' => [
                'table' => 'reach_content_items', 'from' => 'reach_content_items ci', 'at' => 'ci.published_at',
                'where' => "ci.content_type = 'knowledge_base' AND {$published}",
                'id' => 'ci.id', 'title' => 'ci.title', 'detail' => 'NULL', 'link' => "'/content/' || ci.id", 'public_url' => $deployUrl, 'extra' => 'NULL',
                'label' => 'Help article published',
            ],
            'release_note_published' => [
                'table' => 'reach_structured_content_submissions', 'from' => 'reach_structured_content_submissions s', 'at' => 's.published_at',
                'where' => "s.content_type = 'release_note' AND s.status = 'published' AND s.published_at IS NOT NULL",
                'id' => 's.id', 'title' => 's.title', 'detail' => "CONCAT_WS(' · ', s.product_slug, s.version)",
                'link' => "CASE WHEN s.content_item_id IS NOT NULL THEN '/content/' || s.content_item_id END", 'public_url' => 's.public_url', 'extra' => 'NULL',
                'label' => 'Release note published',
            ],
            'workflow_guide_published' => [
                'table' => 'reach_structured_content_submissions', 'from' => 'reach_structured_content_submissions s', 'at' => 's.published_at',
                'where' => "s.content_type = 'workflow_guide' AND s.status = 'published' AND s.published_at IS NOT NULL",
                'id' => 's.id', 'title' => 's.title', 'detail' => 's.product_slug',
                'link' => "CASE WHEN s.content_item_id IS NOT NULL THEN '/content/' || s.content_item_id END", 'public_url' => 's.public_url', 'extra' => 'NULL',
                'label' => 'Workflow guide published',
            ],
            'community_answer_published' => [
                'table' => 'reach_community_automation_submissions', 'from' => 'reach_community_automation_submissions c', 'at' => 'c.published_at',
                'where' => "c.status = 'published' AND c.published_at IS NOT NULL",
                'id' => 'c.id', 'title' => 'c.question_title', 'detail' => "CONCAT_WS(' · ', c.category_slug, c.product_slug)",
                'link' => "'/community/automated'", 'public_url' => 'c.public_url', 'extra' => 'NULL',
                'label' => 'Community answer published',
            ],
            'video_published' => [
                'table' => 'reach_video_pipeline_items', 'from' => 'reach_video_pipeline_items v', 'at' => 'v.published_at',
                'where' => 'v.published_at IS NOT NULL',
                'id' => 'v.id', 'title' => 'v.title', 'detail' => "CONCAT_WS(' · ', v.format, v.product_slug)",
                'link' => "'/video'", 'public_url' => 'v.youtube_url', 'extra' => 'NULL',
                'label' => 'Video published',
            ],
            'social_posted' => [
                'table' => 'reach_social_posts', 'from' => 'reach_social_posts p', 'at' => 'p.published_at',
                'where' => "p.status = 'posted' AND p.published_at IS NOT NULL",
                'id' => 'p.id', 'title' => "COALESCE(NULLIF(p.title, ''), LEFT(p.content, 90))", 'detail' => "CONCAT_WS(' · ', p.channel, p.provider)",
                'link' => "'/social/queue'", 'public_url' => 'p.remote_url', 'extra' => 'NULL',
                'label' => 'Social post',
            ],
            'campaign_sent' => [
                'table' => 'reach_campaign_dispatches', 'from' => 'reach_campaign_dispatches cd', 'at' => 'cd.completed_at',
                'where' => "cd.status IN ('completed','partially_completed') AND cd.completed_at IS NOT NULL",
                'id' => 'cd.id', 'title' => "cd.channel || ' dispatch #' || cd.id", 'detail' => "cd.sent_count || ' sent, ' || cd.failed_count || ' failed'",
                'link' => "CASE WHEN cd.campaign_id IS NOT NULL THEN '/campaigns/' || cd.campaign_id ELSE '/campaigns' END", 'public_url' => 'NULL', 'extra' => 'NULL',
                'label' => 'Campaign sent',
            ],
            'cover_generated' => [
                'table' => 'reach_routine_submissions',
                'from' => 'reach_routine_submissions rs LEFT JOIN reach_content_items rci ON rci.id = rs.content_item_id', 'at' => 'rs.updated_at',
                'where' => "rs.cover_status = 'generated' AND rs.cover_url IS NOT NULL",
                'id' => 'rs.id', 'title' => "COALESCE(NULLIF(rci.title, ''), rs.content_type || ' cover')", 'detail' => 'rs.cover_alt',
                'link' => "CASE WHEN rs.content_item_id IS NOT NULL THEN '/content/' || rs.content_item_id END", 'public_url' => 'rs.cover_url', 'extra' => 'NULL',
                'label' => 'Cover generated',
            ],
            'vetting_rejected' => [
                'table' => 'reach_ai_vetting_runs', 'from' => 'reach_ai_vetting_runs vr', 'at' => 'vr.created_at',
                'where' => "vr.decision = 'reject'",
                'id' => 'vr.id', 'title' => "vr.content_type || ' ' || COALESCE(vr.content_ref, '')", 'detail' => 'NULL',
                'link' => 'NULL', 'public_url' => 'NULL',
                'extra' => "json_build_object('content_type', vr.content_type, 'ref', vr.content_ref, 'model', vr.model, 'reasons', vr.reasons_json,
                             'draft_title', COALESCE(vr.final_json->>'title', vr.final_json->>'question_title'))",
                'label' => 'Rejected by vetting',
            ],
            // Same statuses and timestamp as the Job Monitor's filters
            // (JobQueueInsights::applyFilters with status=dead_letter,failed&since_hours=N).
            'job_failed' => [
                'table' => 'reach_jobs', 'from' => 'reach_jobs j', 'at' => 'COALESCE(j.completed_at, j.updated_at)',
                'where' => "j.status IN ('dead_letter','failed')",
                'id' => 'j.id', 'title' => 'j.job_type', 'detail' => 'j.error_message',
                'link' => "'/admin/jobs/' || j.id", 'public_url' => 'NULL', 'extra' => 'NULL',
                'label' => 'Job failed',
            ],
        ];

        $this->sources = [];
        foreach ($all as $kind => $src) {
            $needs = $kind === 'cover_generated' ? ['reach_routine_submissions', 'reach_content_items'] : [$src['table']];
            foreach ($needs as $t) {
                if (! SchemaGuard::hasTable($this->db, $t)) {
                    continue 2;
                }
            }
            $this->sources[$kind] = $src;
        }

        return $this->sources;
    }
}
