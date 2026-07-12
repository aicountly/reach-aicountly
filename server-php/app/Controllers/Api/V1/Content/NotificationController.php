<?php

namespace App\Controllers\Api\V1\Content;

use App\Libraries\NotificationService;

/**
 * In-app notification endpoints.
 *
 * Routes:
 *   GET  /v1/notifications
 *   GET  /v1/notifications/count
 *   POST /v1/notifications/:id/read
 *   POST /v1/notifications/read-all
 */
class NotificationController extends BaseContentController
{
    private NotificationService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new NotificationService();
    }

    public function index()
    {
        $userId = $this->userId();
        return $this->ok(['notifications' => $this->service->getUnread($userId)]);
    }

    public function count()
    {
        return $this->ok(['unread_count' => $this->service->getUnreadCount($this->userId())]);
    }

    public function markRead($id)
    {
        $this->service->markRead((int) $id);
        return $this->ok(['read' => true]);
    }

    public function markAllRead()
    {
        $this->service->markAllRead($this->userId());
        return $this->ok(['read' => true]);
    }
}
