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
                } elseif (!$identity['analytics_eligible']) {
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
}
