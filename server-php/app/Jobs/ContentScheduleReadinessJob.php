<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\NotificationService;

/**
 * Checks content schedules due to publish and marks them as
 * `ready_for_publication` (no actual publishing in Phase 2).
 * Notifies the content owner when a schedule becomes ready.
 *
 * Payload: {}
 */
class ContentScheduleReadinessJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $db       = \Config\Database::connect();
        $notifSvc = new NotificationService();

        // Schedules with scheduled_at <= now, status pending, content is approved
        $due = $db->table('reach_content_schedules cs')
            ->join('reach_content_items ci', 'ci.id = cs.content_item_id', 'left')
            ->where('cs.schedule_status', 'pending')
            ->where('cs.scheduled_at <=', date('Y-m-d H:i:s'))
            ->where('ci.workflow_status', 'scheduled')
            ->where('cs.cancelled_at IS NULL')
            ->where('ci.deleted_at IS NULL')
            ->select('cs.id as schedule_id, cs.content_item_id, ci.title, ci.created_by')
            ->get()
            ->getResultArray();

        $processed = 0;
        foreach ($due as $row) {
            $db->table('reach_content_schedules')
               ->where('id', $row['schedule_id'])
               ->update(['schedule_status' => 'ready', 'updated_at' => date('Y-m-d H:i:s')]);

            $db->table('reach_content_items')
               ->where('id', $row['content_item_id'])
               ->update(['workflow_status' => 'ready_for_publication', 'updated_at' => date('Y-m-d H:i:s')]);

            if ($row['created_by']) {
                $notifSvc->dispatch(
                    (int) $row['created_by'],
                    NotificationService::TYPE_SCHEDULE_CONFIRMED,
                    "Content \"{$row['title']}\" is ready for publication.",
                    [
                        'entity_type' => 'content_item',
                        'entity_id'   => $row['content_item_id'],
                        'action_url'  => "/content/{$row['content_item_id']}",
                        'data'        => ['content_title' => (string) $row['title']],
                    ],
                    null
                );
            }
            $processed++;
        }

        return ['ok' => true, 'schedules_ready' => $processed];
    }
}
