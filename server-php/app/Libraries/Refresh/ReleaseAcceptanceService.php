<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\AuditLogger;
use App\Models\Refresh\DisasterRecoveryTestModel;
use App\Models\Refresh\OperationalReadinessCheckModel;
use App\Models\Refresh\ReadinessFindingModel;
use App\Models\Refresh\ReleaseAcceptanceRecordModel;
use App\Models\Refresh\TechnicalDebtRecordModel;
use RuntimeException;

/**
 * Evaluates release-acceptance gates and persists the go/no-go record.
 *
 * Gates (stricter of FINAL_PRODUCT_READINESS + DR runbook):
 * 1. No open critical/high security findings
 * 2. Required ops check categories have rows and each is passed/not_applicable
 * 3. All four DR test types have at least one passed record
 * 4. No unresolved critical/high technical-debt blockers
 */
class ReleaseAcceptanceService
{
    public const DR_TYPES = [
        'backup_verify',
        'restore_verify',
        'rollback_verify',
        'migration_verify',
    ];

    public const OPS_CATEGORIES = ['deployment', 'backup', 'rollback'];

    public const RECOMMENDATIONS = [
        'ready_controlled',
        'ready_with_limitations',
        'not_ready',
    ];

    public function __construct(
        private ReleaseAcceptanceRecordModel $acceptanceModel,
        private ReadinessFindingModel $findingModel,
        private OperationalReadinessCheckModel $opsCheckModel,
        private DisasterRecoveryTestModel $drModel,
        private TechnicalDebtRecordModel $debtModel,
        private AuditLogger $auditLogger,
    ) {}

    public function getLatest(): ?array
    {
        $row = $this->acceptanceModel->getLatest();
        return $row ? $this->decodeRow($row) : null;
    }

    /**
     * @return array{
     *   ready: bool,
     *   checks: list<array{key:string,label:string,passed:bool,detail:string,href:?string}>,
     *   open_finding_count: int,
     *   open_debt_blocker_count: int,
     *   dr_passed: array<string,bool>,
     *   ops_summary: array{total:int,passed:int,pending:int,failed:int}
     * }
     */
    public function evaluatePrerequisites(): array
    {
        $openFindings = $this->findingModel->getOpenBlockers();
        $findingsPass = $openFindings === [];

        $opsRows = $this->opsCheckModel->forCategories(self::OPS_CATEGORIES);
        $opsEval = $this->evaluateOpsChecks($opsRows);

        $drPassed = [];
        foreach (self::DR_TYPES as $type) {
            $drPassed[$type] = $this->drModel->hasPassedType($type);
        }
        $drPass = ! in_array(false, $drPassed, true);

        $openDebt = $this->debtModel->getOpenBlockers();
        $debtPass = $openDebt === [];

        $checks = [
            [
                'key'    => 'security_findings',
                'label'  => 'Security findings',
                'passed' => $findingsPass,
                'detail' => $findingsPass
                    ? 'No open critical/high findings'
                    : count($openFindings) . ' open critical/high finding(s)',
                'href'   => '/readiness/security',
            ],
            [
                'key'    => 'operational_checks',
                'label'  => 'Operational readiness',
                'passed' => $opsEval['passed'],
                'detail' => $opsEval['detail'],
                'href'   => '/readiness/operations',
            ],
            [
                'key'    => 'disaster_recovery',
                'label'  => 'Disaster recovery',
                'passed' => $drPass,
                'detail' => $drPass
                    ? 'All four DR test types have a passed record'
                    : 'Missing passed DR tests: ' . implode(', ', array_keys(array_filter($drPassed, static fn ($v) => ! $v))),
                'href'   => '/readiness/disaster-recovery',
            ],
            [
                'key'    => 'technical_debt',
                'label'  => 'Technical debt',
                'passed' => $debtPass,
                'detail' => $debtPass
                    ? 'No unresolved critical/high debt blockers'
                    : count($openDebt) . ' unresolved debt blocker(s)',
                'href'   => '/readiness/technical-debt',
            ],
        ];

        $ready = $findingsPass && $opsEval['passed'] && $drPass && $debtPass;

        return [
            'ready'                   => $ready,
            'checks'                  => $checks,
            'open_finding_count'      => count($openFindings),
            'open_debt_blocker_count' => count($openDebt),
            'dr_passed'               => $drPassed,
            'ops_summary'             => $opsEval['summary'],
        ];
    }

    /**
     * @param array{
     *   release_name: string,
     *   recommendation: string,
     *   evidence_summary: string,
     *   blockers_resolved?: bool,
     *   limitations_accepted?: list<mixed>,
     *   accepted_risks?: list<mixed>
     * } $payload
     */
    public function create(array $payload, int $acceptedBy): array
    {
        $releaseName = trim((string) ($payload['release_name'] ?? ''));
        $recommendation = (string) ($payload['recommendation'] ?? '');
        $evidence = trim((string) ($payload['evidence_summary'] ?? ''));
        $limitations = $payload['limitations_accepted'] ?? [];
        $acceptedRisks = $payload['accepted_risks'] ?? [];

        if ($releaseName === '' || strlen($releaseName) > 100) {
            throw new RuntimeException('release_name is required (max 100 characters)');
        }
        if (! in_array($recommendation, self::RECOMMENDATIONS, true)) {
            throw new RuntimeException('recommendation must be ready_controlled, ready_with_limitations, or not_ready');
        }
        if ($evidence === '') {
            throw new RuntimeException('evidence_summary is required');
        }
        if (! is_array($limitations) || ! is_array($acceptedRisks)) {
            throw new RuntimeException('limitations_accepted and accepted_risks must be arrays');
        }

        $prereq = $this->evaluatePrerequisites();

        if ($recommendation !== 'not_ready' && ! $prereq['ready']) {
            throw new RuntimeException(
                'Cannot issue a ready recommendation until all prerequisite checks pass. '
                . 'Complete Security, DR, Operations, and Technical Debt gates first.'
            );
        }

        if ($recommendation === 'ready_with_limitations' && $limitations === []) {
            throw new RuntimeException('ready_with_limitations requires at least one entry in limitations_accepted');
        }

        $latest = $this->acceptanceModel->getLatest();
        if ($latest !== null) {
            throw new RuntimeException(
                'A release acceptance record already exists (id=' . $latest['id'] . '). '
                . 'Only one active acceptance record is allowed.'
            );
        }

        $blockersResolved = array_key_exists('blockers_resolved', $payload)
            ? (bool) $payload['blockers_resolved']
            : $prereq['ready'];

        if ($recommendation !== 'not_ready' && ! $blockersResolved) {
            throw new RuntimeException('Ready recommendations require blockers_resolved=true');
        }

        $id = $this->acceptanceModel->insert([
            'release_name'         => $releaseName,
            'recommendation'       => $recommendation,
            'evidence_summary'     => $evidence,
            'blockers_resolved'    => $blockersResolved,
            'limitations_accepted' => json_encode(array_values($limitations)),
            'accepted_risks'       => json_encode(array_values($acceptedRisks)),
            'prerequisite_checks'  => json_encode($prereq),
            'accepted_by'          => $acceptedBy,
            'accepted_at'          => date('Y-m-d H:i:s'),
        ]);

        if (! $id) {
            throw new RuntimeException('Failed to persist release acceptance record');
        }

        $this->auditLogger->log(
            userId:     $acceptedBy,
            action:     AuditLogger::READINESS_RELEASE_ACCEPTED,
            entityType: 'release_acceptance_record',
            entityId:   (int) $id,
            extra:      [
                'release_name'   => $releaseName,
                'recommendation' => $recommendation,
                'ready'          => $prereq['ready'],
            ],
        );

        $row = $this->acceptanceModel->find($id);
        return $this->decodeRow(is_array($row) ? $row : ['id' => $id]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{passed:bool,detail:string,summary:array{total:int,passed:int,pending:int,failed:int}}
     */
    private function evaluateOpsChecks(array $rows): array
    {
        $summary = ['total' => count($rows), 'passed' => 0, 'pending' => 0, 'failed' => 0];
        $byCategory = [];
        foreach ($rows as $row) {
            $cat = (string) ($row['check_category'] ?? '');
            $byCategory[$cat][] = $row;
            $status = (string) ($row['status'] ?? 'pending');
            if (in_array($status, ['passed', 'not_applicable'], true)) {
                $summary['passed']++;
            } elseif ($status === 'failed') {
                $summary['failed']++;
            } else {
                $summary['pending']++;
            }
        }

        $missingCats = [];
        foreach (self::OPS_CATEGORIES as $cat) {
            if (empty($byCategory[$cat])) {
                $missingCats[] = $cat;
            }
        }
        if ($missingCats !== []) {
            return [
                'passed'  => false,
                'detail'  => 'No operational checks recorded for: ' . implode(', ', $missingCats),
                'summary' => $summary,
            ];
        }

        $blocking = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'pending');
            if (! in_array($status, ['passed', 'not_applicable'], true)) {
                $blocking[] = ($row['check_category'] ?? '?') . '/' . ($row['check_name'] ?? '?') . '=' . $status;
            }
        }

        if ($blocking !== []) {
            return [
                'passed'  => false,
                'detail'  => 'Incomplete ops checks: ' . implode('; ', array_slice($blocking, 0, 5)),
                'summary' => $summary,
            ];
        }

        return [
            'passed'  => true,
            'detail'  => $summary['total'] . ' deployment/backup/rollback checks passed or N/A',
            'summary' => $summary,
        ];
    }

    /** @param array<string, mixed> $row */
    private function decodeRow(array $row): array
    {
        foreach (['limitations_accepted', 'accepted_risks', 'prerequisite_checks'] as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            if (is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }
}
