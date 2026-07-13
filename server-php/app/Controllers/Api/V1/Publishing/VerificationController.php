<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;

class VerificationController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $rows = $this->db->table('reach_publication_verifications v')
            ->select('v.*')
            ->orderBy('v.id', 'DESC')
            ->limit(100)
            ->get()->getResultArray();

        return $this->ok($rows);
    }
}
