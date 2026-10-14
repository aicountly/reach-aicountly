<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\ConsoleSyncLogModel;
use Config\Services;

class ConsoleSyncStatusController extends BaseApiController
{
    public function index()
    {
        $m = new ConsoleSyncLogModel();
        $recent = $m->orderBy('attempted_at', 'DESC')->findAll(50);
        $since1h = date('Y-m-d H:i:s', strtotime('-1 hour'));
        return $this->ok([
            'configured'      => Services::consoleAudit()->isConfigured(),
            // Central-audit outbox (POST {CONSOLE_API_URL}/portal/audit): waiting for a retry / given up.
            'outbox_pending'  => (new ConsoleSyncLogModel())->where('status', 'pending')->countAllResults(),
            'outbox_dead'     => (new ConsoleSyncLogModel())->where('status', 'dead')->countAllResults(),
            'recent_events'   => $recent,
            'last_ok_at'      => $m->where('ok', true)->orderBy('attempted_at', 'DESC')->first()['attempted_at'] ?? null,
            'last_error_at'   => $m->where('ok', false)->orderBy('attempted_at', 'DESC')->first()['attempted_at'] ?? null,
            'errors_last_hour'=> $m->where('ok', false)->where('attempted_at >=', $since1h)->countAllResults(),
            'ok_last_hour'    => $m->where('ok', true)->where('attempted_at >=', $since1h)->countAllResults(),
        ]);
    }
}
