<?php

declare(strict_types=1);

namespace App\Libraries\Refresh;

use App\Libraries\AuditLogger;
use App\Models\Refresh\DisasterRecoveryTestModel;
use RuntimeException;

/**
 * Records disaster recovery test evidence.
 *
 * DR tests are conducted manually in local or staging environments.
 * This service records the test result and evidence for audit trail.
 *
 * Restrictions:
 * - Environment must be 'local' or 'staging' — production DR testing
 *   is not permitted via this service
 * - Each test record is immutable after creation
 */
class DisasterRecoveryService
{
    public function __construct(
        private DisasterRecoveryTestModel $testModel,
        private AuditLogger               $auditLogger,
    ) {}

    public function recordTest(
        string $testType,
        string $environment,
        string $status,
        ?int   $rpoMinutes,
        ?int   $rtoMinutes,
        string $procedureFollowed,
        string $evidenceNotes,
        int    $testedBy,
    ): array {
        if ($environment === 'production') {
            throw new RuntimeException('DR tests may not be recorded as production via this service');
        }

        $id = $this->testModel->insert([
            'test_type'           => $testType,
            'environment'         => $environment,
            'status'              => $status,
            'rpo_minutes'         => $rpoMinutes,
            'rto_minutes'         => $rtoMinutes,
            'procedure_followed'  => $procedureFollowed,
            'evidence_notes'      => $evidenceNotes,
            'tested_by'           => $testedBy,
            'tested_at'           => date('Y-m-d H:i:s'),
        ]);

        $this->auditLogger->log(
            userId:     $testedBy,
            action:     AuditLogger::READINESS_DR_TEST_RECORDED,
            entityType: 'disaster_recovery_test',
            entityId:   $id,
            extra:      [
                'test_type'   => $testType,
                'environment' => $environment,
                'status'      => $status,
                'rpo_minutes' => $rpoMinutes,
                'rto_minutes' => $rtoMinutes,
            ],
        );

        return $this->testModel->find($id);
    }

    public function getAll(): array
    {
        return $this->testModel->orderBy('tested_at', 'DESC')->findAll();
    }

    public function hasPassed(string $testType): bool
    {
        return $this->testModel
            ->where('test_type', $testType)
            ->where('status', 'passed')
            ->countAllResults() > 0;
    }
}
