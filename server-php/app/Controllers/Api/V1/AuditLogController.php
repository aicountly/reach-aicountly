<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\AuditLogModel;

class AuditLogController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new AuditLogModel();
        foreach (['action', 'entity_type'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $q->where($f, $v);
            }
        }
        $userId = (int) ($this->request->getGet('user_id') ?? 0);
        if ($userId > 0) {
            $q->where('user_id', $userId);
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }
}
