<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Refresh\DisasterRecoveryService;
use App\Libraries\Refresh\ReleaseAcceptanceService;
use App\Models\Refresh\DisasterRecoveryTestModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class DisasterRecoveryController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_disaster_recovery_tests')) {
                return $this->ok([
                    'tests'  => [],
                    'status' => $this->typeStatus([]),
                ]);
            }

            $tests = $this->service()->getAll();
            return $this->ok([
                'tests'  => $tests,
                'status' => $this->typeStatus($tests),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'DisasterRecoveryController::index: ' . $e->getMessage());
            return $this->ok(['tests' => [], 'status' => $this->typeStatus([])]);
        }
    }

    public function store(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Authentication required', 401);
        }

        $body = $this->input();
        $testType = (string) ($body['test_type'] ?? '');
        $environment = (string) ($body['environment'] ?? 'local');
        $status = (string) ($body['status'] ?? 'passed');
        $procedure = trim((string) ($body['procedure_followed'] ?? ''));
        $evidence = trim((string) ($body['evidence_notes'] ?? ''));

        if (! in_array($testType, ReleaseAcceptanceService::DR_TYPES, true)) {
            return $this->fail('test_type must be one of: ' . implode(', ', ReleaseAcceptanceService::DR_TYPES), 422);
        }
        if (! in_array($environment, ['local', 'staging'], true)) {
            return $this->fail('environment must be local or staging', 422);
        }
        if (! in_array($status, ['pending', 'passed', 'failed', 'skipped'], true)) {
            return $this->fail('status is invalid', 422);
        }
        if ($procedure === '' || $evidence === '') {
            return $this->fail('procedure_followed and evidence_notes are required', 422);
        }

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_disaster_recovery_tests')) {
                return $this->fail('DR test storage is not available. Run database migrations first.', 503);
            }

            $row = $this->service()->recordTest(
                $testType,
                $environment,
                $status,
                isset($body['rpo_minutes']) ? (int) $body['rpo_minutes'] : null,
                isset($body['rto_minutes']) ? (int) $body['rto_minutes'] : null,
                $procedure,
                $evidence,
                $userId,
            );

            return $this->ok($row, 201);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'DisasterRecoveryController::store: ' . $e->getMessage());
            return $this->fail('Unable to record DR test', 500);
        }
    }

    private function service(): DisasterRecoveryService
    {
        return new DisasterRecoveryService(
            new DisasterRecoveryTestModel(),
            new AuditLogger(),
        );
    }

    /**
     * @param list<array<string, mixed>> $tests
     * @return array<string, string>
     */
    private function typeStatus(array $tests): array
    {
        $status = array_fill_keys(ReleaseAcceptanceService::DR_TYPES, 'pending');
        foreach ($tests as $test) {
            $type = (string) ($test['test_type'] ?? '');
            if (! isset($status[$type])) {
                continue;
            }
            $s = (string) ($test['status'] ?? 'pending');
            if ($s === 'passed' || $status[$type] !== 'passed') {
                // Prefer passed over other statuses once seen
                if ($status[$type] !== 'passed') {
                    $status[$type] = $s;
                }
            }
        }
        return $status;
    }
}
