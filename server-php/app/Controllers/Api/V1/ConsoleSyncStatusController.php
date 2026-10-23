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
        $since1h = date('Y-m-d H:i:s', strtotime('-1 hour'));

        // ?result=ok|error and ?since_hours=N narrow the events table with the
        // tiles' predicates (ok flag, attempted_at window), so "Errors (last
        // hour)" opens exactly the rows it counted.
        $events = new ConsoleSyncLogModel();
        $result = (string) $this->request->getGet('result');
        if ($result === 'ok' || $result === 'error') {
            $events->where('ok', $result === 'ok');
        }
        $hours = trim((string) $this->request->getGet('since_hours'));
        if ($hours !== '' && ctype_digit($hours) && (int) $hours >= 1 && (int) $hours <= 24 * 90) {
            $events->where('attempted_at >=', date('Y-m-d H:i:s', strtotime('-' . (int) $hours . ' hours')));
        }
        $eventsTotal = $events->countAllResults(false);
        $recent = $events->orderBy('attempted_at', 'DESC')->findAll(50);
        return $this->ok([
            'configured'      => Services::consoleAudit()->isConfigured(),
            // Central-audit outbox (POST {CONSOLE_API_URL}/portal/audit): waiting for a retry / given up.
            'outbox_pending'  => (new ConsoleSyncLogModel())->where('status', 'pending')->countAllResults(),
            'outbox_dead'     => (new ConsoleSyncLogModel())->where('status', 'dead')->countAllResults(),
            'recent_events'   => $recent,
            'events_total'    => $eventsTotal,
            'last_ok_at'      => $m->where('ok', true)->orderBy('attempted_at', 'DESC')->first()['attempted_at'] ?? null,
            'last_error_at'   => $m->where('ok', false)->orderBy('attempted_at', 'DESC')->first()['attempted_at'] ?? null,
            'errors_last_hour'=> $m->where('ok', false)->where('attempted_at >=', $since1h)->countAllResults(),
            'ok_last_hour'    => $m->where('ok', true)->where('attempted_at >=', $since1h)->countAllResults(),
        ]);
    }
}
