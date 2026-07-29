<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Models\Refresh\TechnicalDebtRecordModel;
use CodeIgniter\HTTP\ResponseInterface;

class TechnicalDebtController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! $db->tableExists('reach_technical_debt_records')) {
                return $this->ok([]);
            }

            $tenantId = (int) ($this->request->getGet('tenant_id') ?? 1);
            $model    = new TechnicalDebtRecordModel();
            $rows     = $model->where('tenant_id', $tenantId)
                ->orderBy('id', 'DESC')
                ->findAll(200);

            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'TechnicalDebtController::index: ' . $e->getMessage());
            return $this->ok([]);
        }
    }
}
