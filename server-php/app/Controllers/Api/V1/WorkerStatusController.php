<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\WorkerHealthSnapshotModel;
use Config\Services;

class WorkerStatusController extends BaseApiController
{
    public function index()
    {
        $m = new WorkerHealthSnapshotModel();
        return $this->ok([
            'configured' => (string) env('WORKER_BASE_URL', '') !== ''
                         && (string) env('WORKER_API_TOKEN', '') !== '',
            'recent'     => $m->orderBy('checked_at', 'DESC')->findAll(50),
            'last_ok'    => $m->where('ok', true)->orderBy('checked_at', 'DESC')->first(),
            'last_error' => $m->where('ok', false)->orderBy('checked_at', 'DESC')->first(),
        ]);
    }

    public function ping()
    {
        $res = Services::workerClient()->pingAndRecord();
        $this->audit('worker.health_ping', 'worker', null, null, ['ok' => $res['ok'], 'status' => $res['status']]);
        return $this->ok($res);
    }
}
