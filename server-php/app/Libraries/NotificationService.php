<?php

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\NotificationPreferenceModel;
use App\Models\NotificationDeliveryModel;

/**
 * In-app notification dispatch and management.
 *
 * Phase 2: in-app delivery only. Email delivery is scaffolded but disabled
 * (controlled by NotificationPreference.email_enabled which defaults to false).
 */
class NotificationService
{
    // Notification type constants
    public const TYPE_ASSIGNMENT_CREATED     = 'assignment.created';
    public const TYPE_REVIEW_REQUESTED       = 'review.requested';
    public const TYPE_REVIEW_DUE             = 'review.due';
    public const TYPE_REVIEW_OVERDUE         = 'review.overdue';
    public const TYPE_APPROVAL_REQUIRED      = 'approval.required';
    public const TYPE_CONTENT_APPROVED       = 'content.approved';
    public const TYPE_CONTENT_REJECTED       = 'content.rejected';
    public const TYPE_CHANGES_REQUESTED      = 'content.changes_requested';
    public const TYPE_VALIDATION_FAILED      = 'validation.failed';
    public const TYPE_VALIDATION_WAIVED      = 'validation.waived';
    public const TYPE_SCHEDULE_CONFIRMED     = 'schedule.confirmed';
    public const TYPE_SCHEDULE_CANCELLED     = 'schedule.cancelled';
    public const TYPE_REFRESH_DUE            = 'content.refresh_due';
    public const TYPE_DAILY_PACK_GENERATED   = 'daily_pack.generated';
    public const TYPE_DAILY_APPROVAL_DIGEST  = 'daily_pack.approval_digest';

    private NotificationModel            $notifications;
    private NotificationPreferenceModel  $preferences;
    private NotificationDeliveryModel    $deliveries;

    public function __construct()
    {
        $this->notifications = new NotificationModel();
        $this->preferences   = new NotificationPreferenceModel();
        $this->deliveries    = new NotificationDeliveryModel();
    }

    /**
     * Dispatch a notification to a recipient.
     * Respects per-user preferences. In-app always delivered unless explicitly disabled.
     */
    public function dispatch(
        int $recipientId,
        string $type,
        string $message,
        array $options = [],
        ?int $createdBy = null
    ): array {
        $pref = $this->preferences->getPreference($recipientId, $type);
        $inAppEnabled = $pref ? (bool) $pref['in_app_enabled'] : true;

        if (!$inAppEnabled) {
            return [];
        }

        $notificationId = $this->notifications->insert([
            'recipient_id'      => $recipientId,
            'notification_type' => $type,
            'entity_type'       => $options['entity_type'] ?? null,
            'entity_id'         => $options['entity_id'] ?? null,
            'message'           => $message,
            'action_url'        => $options['action_url'] ?? null,
            'data'              => isset($options['data']) ? json_encode($options['data']) : null,
            'created_by'        => $createdBy,
            'created_at'        => date('Y-m-d H:i:s'),
        ], true);

        // Record in-app delivery
        $this->deliveries->insert([
            'notification_id' => $notificationId,
            'channel'         => 'in_app',
            'status'          => 'sent',
            'sent_at'         => date('Y-m-d H:i:s'),
        ]);

        return $this->notifications->find($notificationId);
    }

    public function markRead(int $notificationId): void
    {
        $this->notifications->markRead($notificationId);
    }

    public function markAllRead(int $userId): void
    {
        $this->notifications->markAllReadForUser($userId);
    }

    public function getUnread(int $userId): array
    {
        return $this->notifications->unreadForUser($userId);
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->notifications->unreadCountForUser($userId);
    }

    /** Dispatch to multiple recipients (e.g. all reviewers on a content item). */
    public function dispatchToMany(array $recipientIds, string $type, string $message, array $options = [], ?int $createdBy = null): void
    {
        foreach (array_unique($recipientIds) as $recipientId) {
            $this->dispatch((int) $recipientId, $type, $message, $options, $createdBy);
        }
    }
}
