<?php

namespace App\Libraries;

use App\Models\Content\ContentAssignmentModel;
use App\Models\Content\ContentItemModel;

/**
 * Manages editorial assignments (owner, writer, reviewer, etc.) on content items.
 *
 * Triggers in-app notifications via NotificationService on assignment.
 */
class ContentAssignmentService
{
    private ContentAssignmentModel $assignments;
    private ContentItemModel       $items;
    private AuditLogger            $audit;
    private NotificationService    $notifications;

    public function __construct()
    {
        $this->assignments   = new ContentAssignmentModel();
        $this->items         = new ContentItemModel();
        $this->audit         = new AuditLogger();
        $this->notifications = new NotificationService();
    }

    public function assign(int $contentItemId, int $userId, string $role, array $options = [], array $actor = []): array
    {
        $item = $this->items->find($contentItemId);
        if (!$item) {
            throw new \RuntimeException("Content item {$contentItemId} not found.");
        }

        $existing = $this->assignments->activeAssignment($contentItemId, $userId, $role);
        if ($existing) {
            return $existing;
        }

        $id = $this->assignments->insert([
            'content_item_id' => $contentItemId,
            'user_id'         => $userId,
            'role'            => $role,
            'assigned_at'     => date('Y-m-d H:i:s'),
            'due_date'        => $options['due_date'] ?? null,
            'notes'           => $options['notes'] ?? null,
            'assigned_by'     => $actor['id'] ?? null,
        ], true);

        $assignment = $this->assignments->find($id);

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_ASSIGNED, 'content', $contentItemId, null, null, [
            'user_id' => $userId,
            'role'    => $role,
        ]);

        $this->notifications->dispatch(
            $userId,
            NotificationService::TYPE_ASSIGNMENT_CREATED,
            "You have been assigned as {$role} on \"{$item['title']}\".",
            [
                'entity_type' => 'content_item',
                'entity_id'   => $contentItemId,
                'action_url'  => "/content/{$contentItemId}",
                'data'        => [
                    'content_title' => (string) ($item['title'] ?? ''),
                    'role'          => $role,
                ],
            ],
            $actor['id'] ?? null,
        );

        return $assignment;
    }

    public function unassign(int $contentItemId, int $userId, string $role, array $actor = []): bool
    {
        $existing = $this->assignments->activeAssignment($contentItemId, $userId, $role);
        if (!$existing) {
            return false;
        }

        $this->assignments->update($existing['id'], [
            'unassigned_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_UNASSIGNED, 'content', $contentItemId, null, null, [
            'user_id' => $userId,
            'role'    => $role,
        ]);

        return true;
    }

    public function getAssignments(int $contentItemId): array
    {
        return $this->assignments->forItem($contentItemId);
    }
}
