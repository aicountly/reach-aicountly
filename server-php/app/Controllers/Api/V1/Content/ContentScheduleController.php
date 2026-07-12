<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\ContentScheduleService;

/**
 * Scheduling records for content items.
 *
 * Routes:
 *   GET    /v1/content/items/:id/schedules
 *   POST   /v1/content/items/:id/schedules
 *   DELETE /v1/content/items/:id/schedules/:scheduleId
 */
class ContentScheduleController extends BaseContentController
{
    private ContentScheduleService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new ContentScheduleService();
    }

    public function index($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }
        return $this->ok(['schedules' => $this->service->getSchedules($item['id'])]);
    }

    public function create($id)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body = $this->input();
        if (empty($body['publication_target_id']) || empty($body['scheduled_at'])) {
            return $this->fail('publication_target_id and scheduled_at are required.', 422);
        }

        try {
            $schedule = $this->service->schedule(
                $item['id'],
                (int) $body['publication_target_id'],
                $body['scheduled_at'],
                $body,
                $this->actor()
            );
            return $this->ok($schedule, 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function delete($id, $scheduleId)
    {
        $item = $this->findItem($id);
        if ($item instanceof \CodeIgniter\HTTP\ResponseInterface) {
            return $item;
        }

        $body   = $this->input();
        $reason = trim($body['reason'] ?? '');

        try {
            $schedule = $this->service->cancel((int) $scheduleId, $reason, $this->actor());
            return $this->ok($schedule);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}
