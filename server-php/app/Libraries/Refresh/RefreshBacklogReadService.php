<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Intelligence\ContentIdentityLabels;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Read side of the refresh pipeline for the readiness drill-down:
 *
 *   Operations "Recommendation Backlog" → recommendations(status=recommended,triaged)
 *   Operations "Active Workflows"       → workflows(active=1)
 *   → recommendation() — score components, evidence snapshot, workflow(s)
 *     with brief, version / publication links and outcome windows.
 *
 * List scopes reuse RefreshOperationsService's constants so a card and the
 * list it opens count the same rows. Read-only.
 */
class RefreshBacklogReadService
{
    public const RECOMMENDATION_STATUSES = ['pending', 'recommended', 'triaged', 'accepted', 'rejected', 'deferred', 'superseded', 'expired'];
    public const RISKS                   = ['low', 'medium', 'high', 'critical'];
    public const WORKFLOW_STATUSES       = [
        'accepted', 'brief_prepared', 'draft_generating', 'draft_ready', 'in_review',
        'approved', 'publish_queued', 'published', 'monitoring', 'outcome_recorded',
        'rejected', 'deferred', 'changes_requested', 'blocked', 'cancelled', 'superseded', 'failed', 'withdrawn',
    ];

    private const RISK_ORDER = "CASE r.risk_classification WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END";

    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function isAvailable(): bool
    {
        try {
            return SchemaGuard::hasTable($this->db(), 'reach_refresh_recommendations')
                && SchemaGuard::hasTable($this->db(), 'reach_refresh_workflows');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array{status?:list<string>, risk?:list<string>, content_identity_id?:int, backlog?:bool} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function recommendations(int $tenantId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        if (! $this->isAvailable()) {
            return ['items' => [], 'total' => 0];
        }

        $where = ['r.tenant_id = ?'];
        $binds = [$tenantId];
        $statuses = ! empty($filters['backlog'])
            ? RefreshOperationsService::BACKLOG_STATUSES
            : array_values(array_intersect($filters['status'] ?? [], self::RECOMMENDATION_STATUSES));
        if ($statuses !== []) {
            $where[] = 'r.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($binds, ...$statuses);
        }
        $risks = array_values(array_intersect($filters['risk'] ?? [], self::RISKS));
        if ($risks !== []) {
            $where[] = 'r.risk_classification IN (' . implode(',', array_fill(0, count($risks), '?')) . ')';
            array_push($binds, ...$risks);
        }
        if (($filters['content_identity_id'] ?? 0) > 0) {
            $where[] = 'r.content_identity_id = ?';
            $binds[] = (int) $filters['content_identity_id'];
        }
        $whereSql = implode(' AND ', $where);
        $limit    = max(1, min(200, $limit));
        $offset   = max(0, $offset);
        $hasScores = SchemaGuard::hasTable($this->db(), 'reach_refresh_score_components');

        $rows = $this->db()->query(
            "SELECT r.id, r.uuid, r.content_identity_id, r.status, r.risk_classification, r.confidence,
                    r.effort_estimate, r.cooldown_until, r.due_date, r.assigned_to, r.evidence_snapshot_id,
                    r.created_at, r.updated_at, " .
                    ($hasScores ? '(SELECT SUM(sc.contribution) FROM reach_refresh_score_components sc WHERE sc.recommendation_id = r.id)' : 'NULL') . " AS total_score,
                    w.id AS workflow_id, w.status AS workflow_status
             FROM reach_refresh_recommendations r
             LEFT JOIN LATERAL (
                SELECT id, status FROM reach_refresh_workflows WHERE recommendation_id = r.id ORDER BY id DESC LIMIT 1
             ) w ON TRUE
             WHERE {$whereSql}
             ORDER BY " . self::RISK_ORDER . ", r.created_at DESC, r.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $binds
        )->getResultArray();

        $total = (int) ($this->db()->query("SELECT COUNT(*) AS c FROM reach_refresh_recommendations r WHERE {$whereSql}", $binds)->getRowArray()['c'] ?? 0);

        return ['items' => $this->withLabels(array_map([self::class, 'shapeRecommendation'], $rows)), 'total' => $total];
    }

    /**
     * @param array{status?:list<string>, active?:bool, content_identity_id?:int, recommendation_id?:int} $filters
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function workflows(int $tenantId, array $filters = [], int $limit = 25, int $offset = 0): array
    {
        if (! $this->isAvailable()) {
            return ['items' => [], 'total' => 0];
        }

        $where = ['w.tenant_id = ?'];
        $binds = [$tenantId];
        $statuses = array_values(array_intersect($filters['status'] ?? [], self::WORKFLOW_STATUSES));
        if ($statuses !== []) {
            $where[] = 'w.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($binds, ...$statuses);
        }
        if (! empty($filters['active'])) {
            $closed  = RefreshOperationsService::CLOSED_WORKFLOW_STATUSES;
            $where[] = 'w.status NOT IN (' . implode(',', array_fill(0, count($closed), '?')) . ')';
            array_push($binds, ...$closed);
        }
        foreach (['content_identity_id', 'recommendation_id'] as $key) {
            if (($filters[$key] ?? 0) > 0) {
                $where[] = "w.{$key} = ?";
                $binds[] = (int) $filters[$key];
            }
        }
        $whereSql = implode(' AND ', $where);
        $limit    = max(1, min(200, $limit));
        $offset   = max(0, $offset);

        $rows = $this->db()->query(
            "SELECT w.id, w.uuid, w.recommendation_id, w.content_identity_id, w.status, w.lock_version,
                    w.refresh_objective, w.risk_classification, w.assigned_to, w.due_date,
                    w.approved_at, w.cancelled_at, w.cancel_reason, w.created_at, w.updated_at,
                    r.status AS recommendation_status
             FROM reach_refresh_workflows w
             LEFT JOIN reach_refresh_recommendations r ON r.id = w.recommendation_id
             WHERE {$whereSql}
             ORDER BY w.updated_at DESC, w.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $binds
        )->getResultArray();

        $total = (int) ($this->db()->query("SELECT COUNT(*) AS c FROM reach_refresh_workflows w WHERE {$whereSql}", $binds)->getRowArray()['c'] ?? 0);

        $rows = array_map(static fn (array $r): array => [
            'id'                  => (int) $r['id'],
            'recommendation_id'   => (int) $r['recommendation_id'],
            'content_identity_id' => (int) $r['content_identity_id'],
            'lock_version'        => (int) $r['lock_version'],
        ] + $r, $rows);

        return ['items' => $this->withLabels($rows), 'total' => $total];
    }

    /** One recommendation with everything behind its score, or null. */
    public function recommendation(int $id): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }
        $row = $this->db()->table('reach_refresh_recommendations')->where('id', $id)->get()->getRowArray();
        if (! $row) {
            return null;
        }

        $components = $this->tableRows('reach_refresh_score_components', 'recommendation_id', [$id], 'contribution DESC, id ASC');
        $components = array_map(static fn (array $c): array => [
            'id'           => (int) $c['id'],
            'raw_value'    => $c['raw_value'] !== null ? (float) $c['raw_value'] : null,
            'weight'       => (float) $c['weight'],
            'contribution' => (float) $c['contribution'],
        ] + $c, $components);

        $snapshot = null;
        if (SchemaGuard::hasTable($this->db(), 'reach_refresh_evidence_snapshots') && ! empty($row['evidence_snapshot_id'])) {
            $snap = $this->db()->table('reach_refresh_evidence_snapshots')->where('id', (int) $row['evidence_snapshot_id'])->get()->getRowArray();
            if ($snap) {
                $snapshot = [
                    'id'                 => (int) $snap['id'],
                    'evidence_date'      => $snap['evidence_date'],
                    'window_days'        => (int) $snap['window_days'],
                    'completeness_score' => (float) $snap['completeness_score'],
                    'evidence_packet'    => self::json($snap['evidence_packet'], []),
                    'missing_domains'    => self::json($snap['missing_domains'], []),
                    'freshness_state'    => self::json($snap['freshness_state'], []),
                    'created_at'         => $snap['created_at'] ?? null,
                ];
            }
        }

        $workflows = $this->tableRows('reach_refresh_workflows', 'recommendation_id', [$id], 'id DESC');
        $wfIds     = array_map(static fn (array $w): int => (int) $w['id'], $workflows);
        $briefs    = $this->group($this->tableRows('reach_refresh_briefs', 'workflow_id', $wfIds, 'id ASC'), 'workflow_id');
        $versions  = $this->group($this->tableRows('reach_refresh_content_version_links', 'workflow_id', $wfIds, 'id ASC'), 'workflow_id');
        $pubLinks  = $this->tableRows('reach_refresh_publication_links', 'workflow_id', $wfIds, 'id ASC');
        $windows   = $this->group(
            $this->tableRows('reach_refresh_outcome_windows', 'publication_link_id', array_map(static fn (array $p): int => (int) $p['id'], $pubLinks), 'id ASC'),
            'publication_link_id'
        );

        $pubByWorkflow = [];
        foreach ($pubLinks as $p) {
            $p['outcome_windows'] = $windows[(int) $p['id']] ?? [];
            $pubByWorkflow[(int) $p['workflow_id']][] = $p;
        }

        $workflows = array_map(function (array $w) use ($briefs, $versions, $pubByWorkflow): array {
            $wid   = (int) $w['id'];
            $brief = $briefs[$wid][0] ?? null;
            if ($brief !== null) {
                foreach (['key_changes', 'target_sections', 'source_requirements'] as $k) {
                    $brief[$k] = self::json($brief[$k] ?? null, []);
                }
            }

            return ['id' => $wid] + $w + [
                'brief'             => $brief,
                'version_links'     => $versions[$wid] ?? [],
                'publication_links' => $pubByWorkflow[$wid] ?? [],
            ];
        }, $workflows);

        // Earlier / later recommendations for the same content, so a superseded
        // one can be followed to the one that replaced it.
        $history = $this->db()->table('reach_refresh_recommendations')
            ->select('id, status, risk_classification, created_at')
            ->where('content_identity_id', (int) $row['content_identity_id'])
            ->where('id !=', $id)
            ->orderBy('id', 'DESC')->limit(20)->get()->getResultArray();
        $history = array_map(static fn (array $h): array => ['id' => (int) $h['id']] + $h, $history);

        $shaped = self::shapeRecommendation($row + [
            'total_score' => $components === [] ? null : array_sum(array_column($components, 'contribution')),
        ]);
        $labels = (new ContentIdentityLabels($this->db()))->forIds([$shaped['content_identity_id']]);

        return $shaped + [
            'triage_notes'     => $row['triage_notes'] ?? null,
            'superseded_by'    => isset($row['superseded_by']) ? (int) $row['superseded_by'] : null,
            'policy_version_id' => (int) $row['policy_version_id'],
            'content'          => $labels[$shaped['content_identity_id']] ?? null,
            'score_components' => $components,
            'evidence'         => $snapshot,
            'workflows'        => $workflows,
            'related'          => $history,
        ];
    }

    /** @param array<string,mixed> $r */
    private static function shapeRecommendation(array $r): array
    {
        return [
            'id'                   => (int) $r['id'],
            'uuid'                 => $r['uuid'] ?? null,
            'content_identity_id'  => (int) $r['content_identity_id'],
            'status'               => $r['status'],
            'risk_classification'  => $r['risk_classification'],
            'confidence'           => $r['confidence'] !== null ? (float) $r['confidence'] : null,
            'effort_estimate'      => $r['effort_estimate'] ?? null,
            'total_score'          => isset($r['total_score']) && $r['total_score'] !== null ? round((float) $r['total_score'], 4) : null,
            'cooldown_until'       => $r['cooldown_until'] ?? null,
            'due_date'             => $r['due_date'] ?? null,
            'assigned_to'          => isset($r['assigned_to']) ? (int) $r['assigned_to'] : null,
            'evidence_snapshot_id' => isset($r['evidence_snapshot_id']) ? (int) $r['evidence_snapshot_id'] : null,
            'workflow_id'          => isset($r['workflow_id']) ? (int) $r['workflow_id'] : null,
            'workflow_status'      => $r['workflow_status'] ?? null,
            'created_at'           => $r['created_at'] ?? null,
            'updated_at'           => $r['updated_at'] ?? null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows each with content_identity_id
     * @return list<array<string,mixed>>
     */
    private function withLabels(array $rows): array
    {
        $labels = (new ContentIdentityLabels($this->db()))->forIds(array_column($rows, 'content_identity_id'));

        return array_map(static function (array $r) use ($labels): array {
            $l = $labels[(int) $r['content_identity_id']] ?? null;

            return $r + [
                'content_type'    => $l['content_type'] ?? null,
                'canonical_url'   => $l['canonical_url'] ?? null,
                'content_item_id' => $l['content_item_id'] ?? null,
                'content_title'   => $l['title'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function tableRows(string $table, string $column, array $ids, string $order): array
    {
        if ($ids === [] || ! SchemaGuard::hasTable($this->db(), $table)) {
            return [];
        }
        $builder = $this->db()->table($table)->whereIn($column, $ids);
        foreach (explode(',', $order) as $part) {
            [$col, $dir] = array_pad(explode(' ', trim($part)), 2, 'ASC');
            $builder->orderBy($col, $dir);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int, list<array<string,mixed>>>
     */
    private function group(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row[$key]][] = $row;
        }

        return $out;
    }

    private static function json(mixed $value, mixed $fallback): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return $fallback;
        }
        $decoded = json_decode($value, true);

        return $decoded ?? $fallback;
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}
