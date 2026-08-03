<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use Config\Database;

/**
 * Durable cold-start for empty Knowledge / empty topic backlog.
 *
 * Production often has zero approved topic clusters, which makes discover and
 * the roadmap optimiser no-ops forever. This service seeds a minimal approved
 * foundation plus pinned pilot candidates so cron can create the first briefs.
 */
class BlogColdStartService
{
    /** @var list<array{slug:string,name:string,pillar_topic:string,description:string}> */
    private const DEFAULT_CLUSTERS = [
        [
            'slug'         => 'gst-compliance',
            'name'         => 'GST Compliance',
            'pillar_topic' => 'GST compliance for Indian SMEs',
            'description'  => 'GST returns, ITC, invoices, and day-to-day compliance topics.',
        ],
        [
            'slug'         => 'accounting-basics',
            'name'         => 'Accounting Basics',
            'pillar_topic' => 'Bookkeeping and accounting basics for growing businesses',
            'description'  => 'Practical accounting workflows for founders and finance teams.',
        ],
        [
            'slug'         => 'income-tax-business',
            'name'         => 'Income Tax for Businesses',
            'pillar_topic' => 'Income tax compliance for Indian businesses',
            'description'  => 'TDS, advance tax, and business income-tax fundamentals.',
        ],
    ];

    /** @var list<string> */
    private const DEFAULT_PILOTS = [
        'GST Input Tax Credit Explained for Small Businesses',
        'How to File GSTR-1 Without Common Mistakes',
        'Bookkeeping Checklist for Indian SMEs',
        'TDS Compliance Basics for Growing Companies',
        'Choosing Accounting Software for GST-Registered Businesses',
    ];

    /**
     * Ensure approved clusters + eligible candidates exist.
     *
     * @return array<string,mixed>
     */
    public function bootstrap(int $pilotCount = 5): array
    {
        $clusters = $this->ensureApprovedClusters();
        $pilots   = $this->ensurePilotCandidates($pilotCount);

        return [
            'clusters' => $clusters,
            'pilots'   => $pilots,
            'eligible_candidates' => $this->countEligibleCandidates(),
            'approved_clusters'   => $this->countApprovedClusters(),
        ];
    }

    /**
     * @return array{created:list<int>,existing:int,skipped:bool}
     */
    public function ensureApprovedClusters(): array
    {
        $db = Database::connect();
        if (! $db->tableExists('reach_topic_clusters')) {
            throw new \RuntimeException('reach_topic_clusters table is missing — run migrations.');
        }

        $existing = $this->countApprovedClusters();
        if ($existing > 0) {
            return ['created' => [], 'existing' => $existing, 'skipped' => true];
        }

        $created = [];
        $now     = date('Y-m-d H:i:s');

        foreach (self::DEFAULT_CLUSTERS as $cluster) {
            $row = $db->table('reach_topic_clusters')
                ->where('slug', $cluster['slug'])
                ->get()
                ->getRowArray();

            if ($row) {
                if (($row['status'] ?? '') !== 'approved' || ! empty($row['deleted_at'])) {
                    $db->table('reach_topic_clusters')->where('id', (int) $row['id'])->update([
                        'status'      => 'approved',
                        'deleted_at'  => null,
                        'approved_at' => $now,
                        'updated_at'  => $now,
                        'name'        => $cluster['name'],
                        'pillar_topic'=> $cluster['pillar_topic'],
                        'description' => $cluster['description'],
                    ]);
                }
                $created[] = (int) $row['id'];
                continue;
            }

            $db->table('reach_topic_clusters')->insert([
                'slug'          => $cluster['slug'],
                'name'          => $cluster['name'],
                'pillar_topic'  => $cluster['pillar_topic'],
                'description'   => $cluster['description'],
                'status'        => 'approved',
                'approved_at'   => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $created[] = (int) $db->insertID();
        }

        return ['created' => $created, 'existing' => 0, 'skipped' => false];
    }

    /**
     * Seed pinned pilot candidates when the optimiser backlog is empty.
     *
     * @return array{created:list<array{id:int,title:string}>,skipped:bool,reason:?string}
     */
    public function ensurePilotCandidates(int $limit = 5): array
    {
        $db = Database::connect();
        if (! $db->tableExists('reach_topic_candidates')) {
            throw new \RuntimeException('reach_topic_candidates table is missing — run migrations.');
        }

        $eligible = $this->countEligibleCandidates();
        if ($eligible > 0) {
            return [
                'created' => [],
                'skipped' => true,
                'reason'  => 'eligible_candidates_already_present',
            ];
        }

        $clusterId = $this->firstApprovedClusterId();
        $titles    = array_slice(self::DEFAULT_PILOTS, 0, max(1, $limit));
        $created   = [];
        $now       = date('Y-m-d H:i:s');

        foreach ($titles as $title) {
            $normalized = strtolower(trim($title));
            $exists = $db->table('reach_topic_candidates')
                ->where('normalized_title', $normalized)
                ->whereIn('status', ['candidate', 'scored', 'roadmap_selected'])
                ->countAllResults();
            if ($exists > 0) {
                continue;
            }

            $db->table('reach_topic_candidates')->insert([
                'candidate_uuid'   => bin2hex(random_bytes(16)),
                'topic_cluster_id' => $clusterId,
                'title'            => $title,
                'normalized_title' => $normalized,
                'slug_hint'        => trim((string) preg_replace('/[^a-z0-9]+/', '-', $normalized), '-'),
                'portfolio_stream' => 'marketing',
                'status'           => 'candidate',
                'source'           => 'manual_pilot',
                'is_human_pinned'  => true,
                'is_locked'        => false,
                'evidence_ready'   => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
            $created[] = [
                'id'    => (int) $db->insertID(),
                'title' => $title,
            ];
        }

        return [
            'created' => $created,
            'skipped' => $created === [],
            'reason'  => $created === [] ? 'pilots_already_exist_or_exhausted' : null,
        ];
    }

    public function countApprovedClusters(): int
    {
        $db = Database::connect();
        if (! $db->tableExists('reach_topic_clusters')) {
            return 0;
        }

        return (int) $db->table('reach_topic_clusters')
            ->where('status', 'approved')
            ->where('deleted_at IS NULL', null, false)
            ->countAllResults();
    }

    public function countEligibleCandidates(): int
    {
        $db = Database::connect();
        if (! $db->tableExists('reach_topic_candidates')) {
            return 0;
        }

        $row = $db->query(
            "SELECT COUNT(*) AS cnt
             FROM reach_topic_candidates
             WHERE status IN ('candidate', 'scored')
               AND is_locked = FALSE
               AND (
                 is_human_pinned = TRUE
                 OR cooldown_until IS NULL
                 OR cooldown_until <= ?
               )",
            [date('Y-m-d H:i:s')]
        )->getRowArray();

        return (int) ($row['cnt'] ?? 0);
    }

    private function firstApprovedClusterId(): ?int
    {
        $db = Database::connect();
        $row = $db->table('reach_topic_clusters')
            ->select('id')
            ->where('status', 'approved')
            ->where('deleted_at IS NULL', null, false)
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        return $row ? (int) $row['id'] : null;
    }
}
