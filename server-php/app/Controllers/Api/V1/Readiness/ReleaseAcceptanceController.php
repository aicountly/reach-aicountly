<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Libraries\AuditLogger;
use App\Libraries\Refresh\ReleaseAcceptanceService;
use App\Models\Refresh\DisasterRecoveryTestModel;
use App\Models\Refresh\OperationalReadinessCheckModel;
use App\Models\Refresh\ReadinessFindingModel;
use App\Models\Refresh\ReleaseAcceptanceRecordModel;
use App\Models\Refresh\TechnicalDebtRecordModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

class ReleaseAcceptanceController extends BaseApiController
{
    public function prerequisites(): ResponseInterface
    {
        try {
            if (! $this->tableReady('reach_release_acceptance_records')) {
                return $this->ok($this->emptyPrereq('Release acceptance tables are not migrated yet.'));
            }
            return $this->ok($this->service()->evaluatePrerequisites());
        } catch (\Throwable $e) {
            log_message('error', 'ReleaseAcceptanceController::prerequisites: ' . $e->getMessage());
            return $this->fail('Unable to evaluate release prerequisites', 500);
        }
    }

    public function latest(): ResponseInterface
    {
        try {
            if (! $this->tableReady('reach_release_acceptance_records')) {
                return $this->ok(null);
            }
            return $this->ok($this->service()->getLatest());
        } catch (\Throwable $e) {
            log_message('error', 'ReleaseAcceptanceController::latest: ' . $e->getMessage());
            return $this->fail('Unable to load release acceptance record', 500);
        }
    }

    public function create(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Authentication required', 401);
        }

        try {
            if (! $this->tableReady('reach_release_acceptance_records')) {
                return $this->fail('Release acceptance storage is not available. Run database migrations first.', 503);
            }

            $body = $this->input();
            $record = $this->service()->create($body, $userId);
            return $this->ok($record, 201);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            log_message('error', 'ReleaseAcceptanceController::create: ' . $e->getMessage());
            return $this->fail('Unable to create release acceptance record', 500);
        }
    }

    private function service(): ReleaseAcceptanceService
    {
        return new ReleaseAcceptanceService(
            new ReleaseAcceptanceRecordModel(),
            new ReadinessFindingModel(),
            new OperationalReadinessCheckModel(),
            new DisasterRecoveryTestModel(),
            new TechnicalDebtRecordModel(),
            new AuditLogger(),
        );
    }

    private function tableReady(string $table): bool
    {
        try {
            return \Config\Database::connect()->tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function emptyPrereq(string $detail): array
    {
        return [
            'ready'                   => false,
            'checks'                  => [[
                'key'    => 'migrations',
                'label'  => 'Database migrations',
                'passed' => false,
                'detail' => $detail,
                'href'   => null,
            ]],
            'open_finding_count'      => 0,
            'open_debt_blocker_count' => 0,
            'dr_passed'               => array_fill_keys(ReleaseAcceptanceService::DR_TYPES, false),
            'ops_summary'             => ['total' => 0, 'passed' => 0, 'pending' => 0, 'failed' => 0],
        ];
    }
}
