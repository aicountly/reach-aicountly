<?php

namespace App\Jobs;

use App\Libraries\JobContext;
use App\Libraries\JobHandlerInterface;
use App\Libraries\NotificationService;
use App\Libraries\Support\DisplayTime;

/**
 * Sends reminders to content owners/writers whose items are due within 24 h.
 *
 * Payload: {}
 */
class ContentDueDateReminderJob implements JobHandlerInterface
{
    public function handle(array $payload, JobContext $ctx): array
    {
        $db       = \Config\Database::connect();
        $notifSvc = new NotificationService();

        // Items due within the next 24 h that haven't been approved yet
        $items = $db->table('reach_content_items ci')
            ->join('reach_content_briefs b', 'b.content_item_id = ci.id', 'left')
            ->whereIn('ci.workflow_status', ['draft', 'validation_pending', 'review_pending'])
            ->where('b.due_date IS NOT NULL')
            ->where('b.due_date <=', date('Y-m-d', strtotime('+1 day')))
            ->where('b.due_date >=', date('Y-m-d'))
            ->where('ci.deleted_at IS NULL')
            ->select('ci.id, ci.title, b.due_date')
            ->get()
            ->getResultArray();

        $sent = 0;
        foreach ($items as $item) {
            $assignments = $db->table('reach_content_assignments')
                ->whereIn('role', ['owner', 'writer'])
                ->where('content_item_id', $item['id'])
                // reach_content_assignments has no is_active column (an ended
                // assignment carries unassigned_at); reading it failed the job
                // whenever any item was due.
                ->where('unassigned_at IS NULL', null, false)
                ->select('user_id')
                ->get()
                ->getResultArray();

            foreach ($assignments as $a) {
                if (self::notifiedToday($db, (int) $a['user_id'], NotificationService::TYPE_REVIEW_DUE, (int) $item['id'])) {
                    continue;
                }
                $notifSvc->dispatch(
                    (int) $a['user_id'],
                    NotificationService::TYPE_REVIEW_DUE,
                    "Content \"{$item['title']}\" is due on " . DisplayTime::date($item['due_date']) . '.',
                    [
                        'entity_type' => 'content_item',
                        'entity_id'   => $item['id'],
                        'action_url'  => "/content/{$item['id']}",
                        'data'        => [
                            'content_title' => (string) $item['title'],
                            'due_date'      => (string) $item['due_date'],
                        ],
                    ],
                    null
                );
                $sent++;
            }
        }

        return ['ok' => true, 'reminders_sent' => $sent];
    }

    /**
     * This job is enqueued every hour; without this check the same person got
     * the same reminder for the same item up to 24 times a day.
     */
    private static function notifiedToday(\CodeIgniter\Database\BaseConnection $db, int $recipientId, string $type, int $itemId): bool
    {
        return $db->table('reach_notifications')
            ->where('recipient_id', $recipientId)
            ->where('notification_type', $type)
            ->where('entity_type', 'content_item')
            ->where('entity_id', $itemId)
            ->where('created_at >=', date('Y-m-d 00:00:00'))
            ->countAllResults() > 0;
    }
}
