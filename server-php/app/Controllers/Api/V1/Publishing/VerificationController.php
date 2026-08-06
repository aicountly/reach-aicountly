<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Database\SchemaGuard;

class VerificationController extends BaseApiController
{
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        try {
            $db = \Config\Database::connect();

            if (! SchemaGuard::hasTable($db, 'reach_publication_verifications')) {
                return $this->ok([]);
            }

            // Avoid table aliases in Query Builder — some CI4/Postgres
            // identifier escaping paths quote "table alias" as one name.
            $rows = $db->table('reach_publication_verifications')
                ->select('reach_publication_verifications.*')
                ->orderBy('reach_publication_verifications.id', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();

            return $this->ok(is_array($rows) ? $rows : []);
        } catch (\Throwable $e) {
            log_message('error', 'VerificationController::index: ' . $e->getMessage());
            // List endpoints must not hard-fail the Publishing UI.
            return $this->ok([]);
        }
    }
}
