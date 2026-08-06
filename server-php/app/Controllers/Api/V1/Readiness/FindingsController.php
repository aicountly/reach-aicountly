<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Models\Refresh\ReadinessFindingModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class FindingsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_readiness_findings')) {
                return $this->ok([]);
            }

            $model  = new ReadinessFindingModel();
            $status = $this->request->getGet('resolution_status');
            $severity = $this->request->getGet('severity');

            if (is_string($status) && $status !== '') {
                $model->where('resolution_status', $status);
            }
            if (is_string($severity) && $severity !== '') {
                $model->where('severity', $severity);
            }

            $rows = $model->orderBy('id', 'DESC')->findAll(200);
            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'FindingsController::index: ' . $e->getMessage());
            return $this->ok([]);
        }
    }

    public function blockers(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_readiness_findings')) {
                return $this->ok([]);
            }
            return $this->ok((new ReadinessFindingModel())->getOpenBlockers());
        } catch (\Throwable $e) {
            log_message('error', 'FindingsController::blockers: ' . $e->getMessage());
            return $this->ok([]);
        }
    }
}
