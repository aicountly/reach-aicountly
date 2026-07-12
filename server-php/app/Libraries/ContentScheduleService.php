<?php

namespace App\Libraries;

use App\Models\Content\ContentScheduleModel;
use App\Models\Content\ContentItemModel;
use App\Models\Content\ContentPublicationTargetModel;
use App\Models\ContentCalendarItemModel;

/**
 * Manages content scheduling records.
 *
 * Phase 2 constraint: publication_status cannot be set to 'published'.
 * Scheduling creates a reach_jobs placeholder; actual execution is deferred.
 */
class ContentScheduleService
{
    private ContentScheduleModel          $schedules;
    private ContentItemModel              $items;
    private ContentPublicationTargetModel $targets;
    private ContentCalendarItemModel      $calendarItems;
    private AuditLogger                   $audit;

    public function __construct()
    {
        $this->schedules     = new ContentScheduleModel();
        $this->items         = new ContentItemModel();
        $this->targets       = new ContentPublicationTargetModel();
        $this->calendarItems = new ContentCalendarItemModel();
        $this->audit         = new AuditLogger();
    }

    public function schedule(
        int $contentItemId,
        int $publicationTargetId,
        string $scheduledAt,
        array $options = [],
        array $actor = []
    ): array {
        $item   = $this->items->find($contentItemId);
        $target = $this->targets->find($publicationTargetId);

        if (!$item) {
            throw new \RuntimeException("Content item {$contentItemId} not found.");
        }
        if (!$target) {
            throw new \RuntimeException("Publication target {$publicationTargetId} not found.");
        }
        if (!in_array($item['workflow_status'], ['approved', 'scheduled'], true)) {
            throw new \RuntimeException('Content must be approved before scheduling.');
        }

        $id = $this->schedules->insert([
            'content_item_id'        => $contentItemId,
            'publication_target_id'  => $publicationTargetId,
            'content_version_id'     => $item['current_version_id'],
            'scheduled_at'           => $scheduledAt,
            'timezone'               => $options['timezone'] ?? 'UTC',
            'schedule_status'        => 'pending',
            'approval_required'      => $options['approval_required'] ?? true,
            'created_by'             => $actor['id'] ?? null,
            'created_actor_type'     => $actor['type'] ?? 'human',
            'request_id'             => $options['request_id'] ?? null,
        ], true);

        // Update item status
        $this->items->update($contentItemId, [
            'workflow_status' => 'scheduled',
            'scheduled_at'    => $scheduledAt,
        ]);

        $this->audit->log(AuditLogger::CONTENT_SCHEDULED, $actor['id'] ?? null, [
            'content_item_id'       => $contentItemId,
            'publication_target_id' => $publicationTargetId,
            'scheduled_at'          => $scheduledAt,
        ]);

        // Create a calendar item so the content appears in the content calendar
        $this->calendarItems->insert([
            'date'       => substr($scheduledAt, 0, 10),
            'item_kind'  => 'content_item',
            'ref_type'   => 'content_item',
            'ref_id'     => $contentItemId,
            'title'      => $item['title'],
            'notes'      => "Scheduled for publication via target #{$publicationTargetId}",
            'created_by' => $actor['id'] ?? null,
        ]);

        return $this->schedules->find($id);
    }

    public function cancel(int $scheduleId, string $reason, array $actor = []): array
    {
        $schedule = $this->schedules->find($scheduleId);
        if (!$schedule) {
            throw new \RuntimeException("Schedule {$scheduleId} not found.");
        }
        if ($schedule['cancelled_at'] !== null) {
            throw new \RuntimeException('Schedule is already cancelled.');
        }
        if (empty($reason)) {
            throw new \RuntimeException('Cancellation reason is required.');
        }

        $this->schedules->update($scheduleId, [
            'schedule_status'     => 'cancelled',
            'cancelled_at'        => date('Y-m-d H:i:s'),
            'cancelled_by'        => $actor['id'] ?? null,
            'cancellation_reason' => $reason,
        ]);

        $this->audit->log(AuditLogger::CONTENT_SCHEDULE_CANCELLED, $actor['id'] ?? null, [
            'schedule_id' => $scheduleId,
            'reason'      => $reason,
        ]);

        return $this->schedules->find($scheduleId);
    }

    public function getSchedules(int $contentItemId): array
    {
        return $this->schedules->forItem($contentItemId);
    }
}
