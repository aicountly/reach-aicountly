<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ConsoleSyncLogModel;

class ConsoleSyncStatusController extends BaseApiController
{
    public function index()
    {
        $m = new ConsoleSyncLogModel();
        $recent = $m->orderBy('attempted_at', 'DESC')->findAll(50);
        $since1h = date('Y-m-d H:i:s', strtotime('-1 hour'));
        return $this->ok([
            'configured'      => (string) env('CONSOLE_API_BASE_URL', '') !== ''
                              && (string) env('CONSOLE_API_TOKEN', '') !== '',
            'recent_events'   => $recent,
            'last_ok_at'      => $m->where('ok', true)->orderBy('attempted_at', 'DESC')->first()['attempted_at'] ?? null,
            'last_error_at'   => $m->where('ok', false)->orderBy('attempted_at', 'DESC')->first()['attempted_at'] ?? null,
            'errors_last_hour'=> $m->where('ok', false)->where('attempted_at >=', $since1h)->countAllResults(),
            'ok_last_hour'    => $m->where('ok', true)->where('attempted_at >=', $since1h)->countAllResults(),
        ]);
    }
}
