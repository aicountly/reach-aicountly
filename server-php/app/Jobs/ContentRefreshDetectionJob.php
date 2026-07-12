<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\NotificationService;

/**
 * Detects content items in `published` status that haven't been refreshed
 * within the refresh window and transitions them to `refresh_due`.
 *
 * Payload: {}
 */
class ContentRefreshDetectionJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $db       = \Config\Database::connect();
        $notifSvc = new NotificationService();

        // Items published more than 90 days ago that are still 'published'
        $stale = $db->table('reach_content_items')
            ->where('workflow_status', 'published')
            ->where('published_at <', date('Y-m-d H:i:s', strtotime('-90 days')))
            ->where('deleted_at IS NULL')
            ->select('id, title, created_by')
            ->get()
            ->getResultArray();

        $transitioned = 0;
        foreach ($stale as $item) {
            $db->table('reach_content_items')
               ->where('id', $item['id'])
               ->update(['workflow_status' => 'refresh_due', 'updated_at' => date('Y-m-d H:i:s')]);

            if ($item['created_by']) {
                $notifSvc->dispatch(
                    (int) $item['created_by'],
                    NotificationService::TYPE_REFRESH_DUE,
                    "Content \"{$item['title']}\" is due for a refresh.",
                    ['entity_type' => 'content_item', 'entity_id' => $item['id'], 'action_url' => "/content/{$item['id']}"],
                    null
                );
            }
            $transitioned++;
        }

        return ['ok' => true, 'refresh_due' => $transitioned];
    }
}
