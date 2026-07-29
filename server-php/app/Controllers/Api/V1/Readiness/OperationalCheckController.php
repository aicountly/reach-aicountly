<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Libraries\Refresh\ReleaseAcceptanceService;
use App\Models\Refresh\OperationalReadinessCheckModel;
use CodeIgniter\HTTP\ResponseInterface;

class OperationalCheckController extends BaseApiController
{
    /** Default checklist seeded when none exist for required categories. */
    private const DEFAULT_CHECKS = [
        ['check_category' => 'deployment', 'check_name' => 'deploy_runbook_reviewed'],
        ['check_category' => 'deployment', 'check_name' => 'staging_smoke_passed'],
        ['check_category' => 'deployment', 'check_name' => 'rollback_plan_documented'],
        ['check_category' => 'backup', 'check_name' => 'backup_procedure_documented'],
        ['check_category' => 'backup', 'check_name' => 'backup_job_verified'],
        ['check_category' => 'rollback', 'check_name' => 'rollback_runbook_tested'],
        ['check_category' => 'rollback', 'check_name' => 'rollback_procedure_documented'],
        ['check_category' => 'monitoring', 'check_name' => 'alerting_configured'],
        ['check_category' => 'provider', 'check_name' => 'provider_health_reviewed'],
        ['check_category' => 'migration', 'check_name' => 'migration_lifecycle_verified'],
    ];

    public function index(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_operational_readiness_checks')) {
                return $this->ok([]);
            }

            $model = new OperationalReadinessCheckModel();
            $rows  = $model->orderBy('check_category', 'ASC')->orderBy('check_name', 'ASC')->findAll();
            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'OperationalCheckController::index: ' . $e->getMessage());
            return $this->ok([]);
        }
    }

    /** Ensure default checks exist (idempotent). */
    public function ensureDefaults(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Authentication required', 401);
        }

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_operational_readiness_checks')) {
                return $this->fail('Operational check storage is not available. Run database migrations first.', 503);
            }

            $model = new OperationalReadinessCheckModel();
            $created = 0;
            foreach (self::DEFAULT_CHECKS as $check) {
                $existing = $model
                    ->where('check_category', $check['check_category'])
                    ->where('check_name', $check['check_name'])
                    ->first();
                if ($existing) {
                    continue;
                }
                $model->insert([
                    'check_category' => $check['check_category'],
                    'check_name'     => $check['check_name'],
                    'status'         => 'pending',
                    'evidence'       => null,
                    'checked_at'     => null,
                    'checked_by'     => null,
                ]);
                $created++;
            }

            $rows = $model->orderBy('check_category', 'ASC')->orderBy('check_name', 'ASC')->findAll();
            return $this->ok([
                'created' => $created,
                'checks'  => is_array($rows) ? $rows : [],
                'required_categories' => ReleaseAcceptanceService::OPS_CATEGORIES,
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'OperationalCheckController::ensureDefaults: ' . $e->getMessage());
            return $this->fail('Unable to ensure default operational checks', 500);
        }
    }

    public function upsert(): ResponseInterface
    {
        $userId = $this->userId();
        if (! $userId) {
            return $this->fail('Authentication required', 401);
        }

        $body = $this->input();
        $category = trim((string) ($body['check_category'] ?? ''));
        $name     = trim((string) ($body['check_name'] ?? ''));
        $status   = trim((string) ($body['status'] ?? 'pending'));
        $evidence = trim((string) ($body['evidence'] ?? ''));

        $allowedStatus = ['pending', 'passed', 'failed', 'skipped', 'not_applicable'];
        $allowedCats = ['deployment', 'monitoring', 'backup', 'rollback', 'provider', 'migration'];

        if ($category === '' || ! in_array($category, $allowedCats, true)) {
            return $this->fail('check_category is required and must be a valid category', 422);
        }
        if ($name === '') {
            return $this->fail('check_name is required', 422);
        }
        if (! in_array($status, $allowedStatus, true)) {
            return $this->fail('status is invalid', 422);
        }

        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_operational_readiness_checks')) {
                return $this->fail('Operational check storage is not available. Run database migrations first.', 503);
            }

            $model = new OperationalReadinessCheckModel();
            $existing = $model
                ->where('check_category', $category)
                ->where('check_name', $name)
                ->first();

            $payload = [
                'check_category' => $category,
                'check_name'     => $name,
                'status'         => $status,
                'evidence'       => $evidence !== '' ? $evidence : null,
                'checked_at'     => in_array($status, ['passed', 'failed', 'not_applicable', 'skipped'], true)
                    ? date('Y-m-d H:i:s')
                    : null,
                'checked_by'     => in_array($status, ['passed', 'failed', 'not_applicable', 'skipped'], true)
                    ? $userId
                    : null,
            ];

            if ($existing) {
                $model->update((int) $existing['id'], $payload);
                $row = $model->find((int) $existing['id']);
            } else {
                $id  = $model->insert($payload);
                $row = $model->find($id);
            }

            return $this->ok($row);
        } catch (\Throwable $e) {
            log_message('error', 'OperationalCheckController::upsert: ' . $e->getMessage());
            return $this->fail('Unable to update operational check', 500);
        }
    }
}
