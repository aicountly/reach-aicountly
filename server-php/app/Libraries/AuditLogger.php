<?php

namespace App\Libraries;

use App\Models\AuditLogModel;
use Config\Services;

/**
 * Local audit writer with automatic Console fan-out for whitelisted event
 * families. Failures never throw — audit must not block business flow.
 */
class AuditLogger
{
    /** Event action prefixes that also get pushed to Console via /audit. */
    private const CONSOLE_FANOUT_PREFIXES = [
        'auth.login', 'auth.logout',
        'bot.', 'approval.', 'blog.', 'campaign.',
        'social.', 'email.', 'whatsapp.', 'lead.',
        'engage_push.', 'settings.', 'publish.',
    ];

    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?array $extra = null,
    ): void {
        try {
            $req = service('request');
            $row = [
                'user_id'     => $userId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_value'   => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_SLASHES) : null,
                'new_value'   => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_SLASHES) : null,
                'ip_address'  => $req->getIPAddress(),
                'user_agent'  => substr((string) $req->getUserAgent(), 0, 510),
                'metadata'    => $extra !== null ? json_encode($extra, JSON_UNESCAPED_SLASHES) : null,
            ];
            (new AuditLogModel())->insert($row);
        } catch (\Throwable $e) {
            log_message('error', 'AuditLogger::log failed: ' . $e->getMessage());
        }

        // Fire-and-forget fan-out to Console for cross-portal visibility.
        if ($this->shouldFanOut($action)) {
            try {
                Services::consoleAudit()->event('reach.' . $action, [
                    'user_id'     => $userId,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'new_value'   => $newValue,
                    'metadata'    => $extra,
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'AuditLogger console fanout failed: ' . $e->getMessage());
            }
        }
    }

    private function shouldFanOut(string $action): bool
    {
        foreach (self::CONSOLE_FANOUT_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
