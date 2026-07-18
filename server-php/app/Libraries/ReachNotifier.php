<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\UserModel;

/**
 * Editorial email/SMS dispatch via Console notification API.
 *
 * Trigger keys match NotificationService type constants and Console
 * product_notification_templates.trigger_key for product_slug = reach.
 */
final class ReachNotifier
{
    /** Notification types that may send SMS (when Console template is active + DLT set). */
    private const SMS_TYPES = [
        NotificationService::TYPE_ASSIGNMENT_CREATED,
        NotificationService::TYPE_REVIEW_REQUESTED,
        NotificationService::TYPE_REVIEW_DUE,
        NotificationService::TYPE_REVIEW_OVERDUE,
        NotificationService::TYPE_CONTENT_APPROVED,
        NotificationService::TYPE_CONTENT_REJECTED,
        NotificationService::TYPE_CHANGES_REQUESTED,
        NotificationService::TYPE_SCHEDULE_CONFIRMED,
        NotificationService::TYPE_REFRESH_DUE,
        NotificationService::TYPE_DAILY_APPROVAL_DIGEST,
    ];

    /** Digest types — respect digest_only preference (skip immediate email if digest_only). */
    private const DIGEST_TYPES = [
        NotificationService::TYPE_DAILY_APPROVAL_DIGEST,
        NotificationService::TYPE_DAILY_PACK_GENERATED,
    ];

    private UserModel $users;

    public function __construct(?UserModel $users = null)
    {
        $this->users = $users ?? new UserModel();
    }

    /**
     * Send email/SMS for an editorial notification when Console templates are active.
     *
     * @param array<string, mixed> $options dispatch() options (entity_type, entity_id, action_url, data)
     */
    public function notifyChannels(
        int $recipientId,
        string $type,
        string $message,
        array $options = [],
        ?array $preference = null,
    ): array {
        $result = ['email' => false, 'sms' => false];

        if (!ConsoleNotificationClient::isConfigured()) {
            return $result;
        }

        $user = $this->users->find($recipientId);
        if (!$user || empty($user['email'])) {
            return $result;
        }

        $email = strtolower(trim((string) $user['email']));
        $vars  = $this->buildVariables($user, $type, $message, $options);

        if ($this->shouldSendEmail($type, $preference)) {
            $subject = $this->subjectFor($type, $vars);
            $result['email'] = ConsoleNotificationClient::sendEmail(
                $type,
                $email,
                $vars,
                $subject,
                $message,
            );
        }

        if ($this->shouldSendSms($type)) {
            $result['sms'] = ConsoleNotificationClient::sendSms($type, $email, $vars, $message);
        }

        return $result;
    }

    /** @param array<string, mixed> $options */
    private function buildVariables(array $user, string $type, string $message, array $options): array
    {
        $data = is_array($options['data'] ?? null) ? $options['data'] : [];
        $base = rtrim(trim((string) (getenv('REACH_APP_URL') ?: $_ENV['REACH_APP_URL'] ?? 'https://reach.aicountly.org')), '/');
        $actionPath = trim((string) ($options['action_url'] ?? ''));
        $actionUrl  = $actionPath !== ''
            ? (str_starts_with($actionPath, 'http') ? $actionPath : $base . $actionPath)
            : $base;

        $contentTitle = (string) ($data['content_title'] ?? $data['title'] ?? '');
        if ($contentTitle === '' && preg_match('/"([^"]+)"/', $message, $m)) {
            $contentTitle = $m[1];
        }

        return [
            'recipientName' => (string) ($user['name'] ?? 'Team member'),
            'email'         => (string) ($user['email'] ?? ''),
            'message'       => $message,
            'content_title' => $contentTitle,
            'title'         => $contentTitle,
            'content_id'    => (string) ($options['entity_id'] ?? $data['content_id'] ?? ''),
            'status'        => (string) ($data['status'] ?? ''),
            'due_date'      => (string) ($data['due_date'] ?? $data['date'] ?? ''),
            'count'         => (string) ($data['count'] ?? ''),
            'date'          => (string) ($data['date'] ?? date('Y-m-d')),
            'action_url'    => $actionUrl,
            'link'          => $actionUrl,
            'url'           => $actionUrl,
        ];
    }

    private function shouldSendEmail(string $type, ?array $preference): bool
    {
        if (!ConsoleNotificationClient::isActive('email', $type)) {
            return false;
        }

        if (in_array($type, self::DIGEST_TYPES, true) && $preference && !empty($preference['digest_only'])) {
            return true;
        }

        if ($preference !== null) {
            return !empty($preference['email_enabled']);
        }

        return in_array($type, [
            NotificationService::TYPE_REVIEW_DUE,
            NotificationService::TYPE_REVIEW_OVERDUE,
            NotificationService::TYPE_DAILY_APPROVAL_DIGEST,
        ], true);
    }

    private function shouldSendSms(string $type): bool
    {
        if (!in_array($type, self::SMS_TYPES, true)) {
            return false;
        }

        return ConsoleNotificationClient::isActive('sms', $type);
    }

    /** @param array<string, scalar|null> $vars */
    private function subjectFor(string $type, array $vars): string
    {
        $title = trim((string) ($vars['content_title'] ?? ''));
        if ($title === '') {
            $title = 'content item';
        }

        return match ($type) {
            NotificationService::TYPE_ASSIGNMENT_CREATED     => "Assigned: {$title}",
            NotificationService::TYPE_REVIEW_REQUESTED       => "Review requested: {$title}",
            NotificationService::TYPE_REVIEW_DUE             => "Due soon: {$title}",
            NotificationService::TYPE_REVIEW_OVERDUE         => "Overdue: {$title}",
            NotificationService::TYPE_CONTENT_APPROVED       => "Approved: {$title}",
            NotificationService::TYPE_CONTENT_REJECTED       => "Rejected: {$title}",
            NotificationService::TYPE_CHANGES_REQUESTED      => "Changes requested: {$title}",
            NotificationService::TYPE_SCHEDULE_CONFIRMED     => "Scheduled: {$title}",
            NotificationService::TYPE_REFRESH_DUE            => "Refresh due: {$title}",
            NotificationService::TYPE_DAILY_APPROVAL_DIGEST  => 'AICOUNTLY Reach — items awaiting review',
            default                                          => 'AICOUNTLY Reach notification',
        };
    }
}
