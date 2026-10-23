<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Readiness;

use App\Controllers\BaseApiController;
use App\Models\Refresh\ReadinessFindingModel;
use CodeIgniter\HTTP\ResponseInterface;
use App\Libraries\Database\SchemaGuard;

class FindingsController extends BaseApiController
{
    private const SEVERITIES          = ['critical', 'high', 'medium', 'low', 'info'];
    private const RESOLUTION_STATUSES = ['open', 'in_progress', 'resolved', 'accepted_risk', 'deferred'];

    public function index(): ResponseInterface
    {
        try {
            $db = \Config\Database::connect();
            if (! SchemaGuard::hasTable($db, 'reach_readiness_findings')) {
                return $this->ok([]);
            }

            $model    = new ReadinessFindingModel();
            $status   = $this->listParam('resolution_status', self::RESOLUTION_STATUSES);
            $severity = $this->listParam('severity', self::SEVERITIES);

            // Comma lists so the dashboard's "open critical" count (open +
            // in_progress, see getOpenBlockers) links to the same rows.
            if ($status !== []) {
                $model->whereIn('resolution_status', $status);
            }
            if ($severity !== []) {
                $model->whereIn('severity', $severity);
            }

            $rows = $model->orderBy('id', 'DESC')->findAll(200);
            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'FindingsController::index: ' . $e->getMessage());
            return $this->ok([]);
        }
    }

    /**
     * A comma-separated query param, keeping only known values.
     *
     * @param list<string> $allowed
     * @return list<string>
     */
    private function listParam(string $key, array $allowed): array
    {
        $raw = $this->request->getGet($key);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $values = array_map('trim', explode(',', strtolower($raw)));

        return array_values(array_unique(array_intersect($values, $allowed)));
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
