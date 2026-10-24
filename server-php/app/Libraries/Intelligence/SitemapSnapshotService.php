<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Models\Intelligence\ContentIdentityModel;
use App\Models\Intelligence\SitemapSnapshotModel;

class SitemapSnapshotService
{
    public function __construct(
        private ContentIdentityModel $identityModel,
        private SitemapSnapshotModel $snapshotModel,
        private AuditLogger          $auditLogger,
    ) {}

    public function generateSnapshot(int $tenantId, string $triggeredBy = 'job'): array
    {
        $snapshotId = $this->snapshotModel->insert([
            'tenant_id'    => $tenantId,
            'status'       => 'pending',
            'triggered_by' => $triggeredBy,
            'generated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $startTime = microtime(true);

            $identities = $this->identityModel->where('tenant_id', $tenantId)
                                              ->where('privacy_class', 'public')
                                              ->findAll();

            $db = $this->snapshotModel->db;

            $total            = 0;
            $included         = 0;
            $excludedNoindex  = 0;
            $excludedWithdrawn = 0;
            $excludedOther    = 0;

            foreach ($identities as $identity) {
                $total++;
                $include        = true;
                $exclusionReason = null;

                if ($identity['publication_status'] === 'withdrawn') {
                    $include         = false;
                    $exclusionReason = 'withdrawn';
                    $excludedWithdrawn++;
                } elseif ($identity['publication_status'] === 'noindex') {
                    $include         = false;
                    $exclusionReason = 'noindex';
                    $excludedNoindex++;
                } elseif ($identity['publication_status'] !== 'published') {
                    $include         = false;
                    $exclusionReason = 'not_published';
                    $excludedOther++;
                } elseif (in_array($identity['analytics_eligible'], [false, 'f', 'false', 0, '0', null], true)) {
                    // Postgres hands booleans back as 't'/'f'; !'f' is false, so a plain negation never excluded anything.
                    $include         = false;
                    $exclusionReason = 'analytics_ineligible';
                    $excludedOther++;
                } else {
                    $included++;
                }

                if (!empty($identity['canonical_url'])) {
                    $db->query(
                        "INSERT INTO reach_sitemap_entries
                         (snapshot_id, content_identity_id, url, last_modified_at, priority, included, exclusion_reason, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                        [
                            $snapshotId,
                            $identity['id'],
                            $identity['canonical_url'],
                            $identity['last_published_at'] ?? date('Y-m-d H:i:s'),
                            $include ? 0.7 : null,
                            $include,
                            $exclusionReason,
                        ]
                    );
                }
            }

            $elapsed = round((microtime(true) - $startTime) * 1000) / 1000;

            $this->snapshotModel->update($snapshotId, [
                'status'            => 'generated',
                'total_entries'     => $total,
                'included_entries'  => $included,
                'excluded_noindex'  => $excludedNoindex,
                'excluded_withdrawn' => $excludedWithdrawn,
                'excluded_other'    => $excludedOther,
                'generation_secs'   => $elapsed,
            ]);

            $snapshot = $this->snapshotModel->find($snapshotId);

            $this->auditLogger->log(null, AuditLogger::SITEMAP_SNAPSHOT_GENERATED, 'sitemap_snapshot', $snapshotId,
                null, ['total' => $total, 'included' => $included], null, 'system');

            return $snapshot;
        } catch (\Throwable $e) {
            $this->snapshotModel->update($snapshotId, [
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            $this->auditLogger->log(null, AuditLogger::SITEMAP_SNAPSHOT_FAILED, 'sitemap_snapshot', $snapshotId,
                null, null, ['error' => $e->getMessage()], 'system');

            throw $e;
        }
    }

    public function getLatestSnapshot(int $tenantId): ?array
    {
        return $this->snapshotModel->getLatest($tenantId);
    }

    public function getSnapshotEntries(int $snapshotId, bool $includedOnly = true): array
    {
        $db    = $this->snapshotModel->db;
        $where = $includedOnly ? 'AND se.included = TRUE' : '';

        return $db->query(
            "SELECT se.*, ci.content_type, ci.canonical_url as identity_canonical
             FROM reach_sitemap_entries se
             LEFT JOIN reach_content_identities ci ON ci.id = se.content_identity_id
             WHERE se.snapshot_id = ? {$where}
             ORDER BY se.priority DESC, se.url",
            [$snapshotId]
        )->getResultArray();
    }

    /** Exclusion reasons generateSnapshot() writes. */
    public const EXCLUSION_REASONS = ['noindex', 'withdrawn', 'not_published', 'analytics_ineligible'];

    /**
     * Entries of one snapshot, filtered the way its counts were taken:
     * state=included|excluded, reason=<exclusion reason>, q=url contains.
     * Each entry carries its content identity so the UI can open the post.
     *
     * Snapshot counts include identities without a canonical URL, which get
     * no entry row — `unlisted` says how many, so the gap is explained.
     *
     * @param array<string, mixed> $filters
     * @return array{snapshot: ?array, rows: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>, facets: array<string, int>, unlisted: int}
     */
    public function listEntries(int $snapshotId, array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $db       = $this->snapshotModel->db;
        $page     = max(1, $page);
        $perPage  = max(1, min(500, $perPage));
        $snapshot = $db->table('reach_sitemap_snapshots')->where('id', $snapshotId)->get()->getRowArray() ?: null;

        $where = ['se.snapshot_id = ?'];
        $binds = [$snapshotId];
        $clean = [];
        $state = (string) ($filters['state'] ?? '');
        if ($state === 'included' || $state === 'excluded') {
            $where[] = $state === 'included' ? 'se.included = TRUE' : 'se.included = FALSE';
            $clean['state'] = $state;
        }
        $reason = (string) ($filters['reason'] ?? '');
        if (in_array($reason, self::EXCLUSION_REASONS, true)) {
            $where[] = 'se.exclusion_reason = ?';
            $binds[] = $clean['reason'] = $reason;
        } elseif ($reason === 'other') {
            // excluded_other = not_published + analytics_ineligible
            $where[] = "se.exclusion_reason IN ('not_published', 'analytics_ineligible')";
            $clean['reason'] = 'other';
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'se.url ILIKE ?';
            $binds[] = '%' . addcslashes(mb_substr($q, 0, 200), '%_\\') . '%';
            $clean['q'] = $q;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) ($db->query("SELECT COUNT(*) AS n FROM reach_sitemap_entries se WHERE {$whereSql}", $binds)->getRowArray()['n'] ?? 0);
        $rows  = $db->query(
            "SELECT se.id, se.snapshot_id, se.content_identity_id, se.url, se.last_modified_at, se.change_frequency, se.priority,
                    se.included, se.exclusion_reason, se.created_at,
                    ci.content_type, ci.source_id, ci.publication_status, ci.analytics_eligible, ci.canonical_url AS identity_canonical
               FROM reach_sitemap_entries se
               LEFT JOIN reach_content_identities ci ON ci.id = se.content_identity_id
              WHERE {$whereSql}
              ORDER BY se.included DESC, se.priority DESC NULLS LAST, se.url
              LIMIT ? OFFSET ?",
            array_merge($binds, [$perPage, ($page - 1) * $perPage])
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['included'] = in_array($row['included'], [true, 't', 'true', 1, '1'], true);
            // A blog identity's source is the content item, so the post opens in Reach.
            $row['content_item_id'] = ($row['content_type'] ?? null) === 'blog' && $row['source_id'] !== null ? (int) $row['source_id'] : null;
        }
        unset($row);

        $facets = ['all' => 0, 'included' => 0, 'excluded' => 0];
        foreach ($db->query(
            'SELECT included, exclusion_reason, COUNT(*) AS n FROM reach_sitemap_entries WHERE snapshot_id = ? GROUP BY included, exclusion_reason',
            [$snapshotId]
        )->getResultArray() as $f) {
            $n = (int) $f['n'];
            $facets['all'] += $n;
            $inc = in_array($f['included'], [true, 't', 'true', 1, '1'], true);
            $facets[$inc ? 'included' : 'excluded'] += $n;
            if (! $inc && $f['exclusion_reason'] !== null) {
                $facets[(string) $f['exclusion_reason']] = ($facets[(string) $f['exclusion_reason']] ?? 0) + $n;
            }
        }

        return [
            'snapshot' => $snapshot,
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'filters'  => $clean,
            'facets'   => $facets,
            'unlisted' => $snapshot ? max(0, (int) $snapshot['total_entries'] - $facets['all']) : 0,
        ];
    }
}
