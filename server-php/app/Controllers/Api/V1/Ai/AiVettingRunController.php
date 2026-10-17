<?php

namespace App\Controllers\Api\V1\Ai;

use App\Controllers\BaseApiController;

/**
 * The review record: every vetting decision (approve / revise / reject /
 * error) with its reasons, newest first.
 */
class AiVettingRunController extends BaseApiController
{
    public function index()
    {
        $q       = $this->request->getGet();
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 25)));
        $page    = max(1, (int) ($q['page'] ?? 1));

        $b = \Config\Database::connect()->table('reach_ai_vetting_runs');
        if (! empty($q['content_type'])) {
            $b->where('content_type', (string) $q['content_type']);
        }
        if (! empty($q['decision'])) {
            $b->where('decision', (string) $q['decision']);
        }
        $total = (clone $b)->countAllResults();
        $rows  = $b->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        foreach ($rows as &$row) {
            foreach (['reasons_json', 'scores_json', 'final_json'] as $col) {
                $row[$col] = is_string($row[$col] ?? null) ? json_decode($row[$col], true) : ($row[$col] ?? null);
            }
        }

        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }
}
