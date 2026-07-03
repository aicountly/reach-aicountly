<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\MarketingBotReportModel;

class BotReportController extends BaseApiController
{
    public function index()
    {
        [$page, $limit, $offset] = $this->pagination();
        $q = new MarketingBotReportModel();
        foreach (['action', 'approval_status', 'publishing_status', 'mode'] as $f) {
            $v = trim((string) $this->request->getGet($f));
            if ($v !== '') {
                $q->where($f, $v);
            }
        }
        $total = $q->countAllResults(false);
        $rows  = $q->orderBy('created_at', 'DESC')->findAll($limit, $offset);
        return $this->ok(['items' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    public function show(int $id)
    {
        $row = (new MarketingBotReportModel())->find($id);
        if (! $row) {
            return $this->fail('Bot report not found.', 404);
        }
        return $this->ok($row);
    }
}
