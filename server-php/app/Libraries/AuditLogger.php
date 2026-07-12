<?php

namespace App\Libraries;

use App\Models\AuditLogModel;
use Config\Services;

/**
 * Local audit writer with automatic Console fan-out for whitelisted event
 * families. Failures never throw — audit must not block business flow.
 *
 * Phase 0 extension:
 *   - Accepts optional actorType/actorService/reason/requestId/jobId args
 *     so async worker events and permission denials can be correlated.
 *   - Redacts old/new/metadata via SecretRedactor before persistence.
 *   - Backwards-compatible: existing callers using the positional signature
 *     `(userId, action, entityType, entityId, old, new, extra)` keep working.
 */
class AuditLogger
{
    /** Event action prefixes that also get pushed to Console via /audit. */
    private const CONSOLE_FANOUT_PREFIXES = [
        'auth.login', 'auth.logout',
        'bot.', 'approval.', 'blog.', 'campaign.',
        'social.', 'email.', 'whatsapp.', 'lead.',
        'engage_push.', 'settings.', 'publish.', 'permission.',
        'security.', 'integration.', 'role.',
    ];

    /**
     * @param ?int    $userId       Reach user id, or null for system/anonymous.
     * @param string  $action       Dotted event slug (see docs/architecture/REACH_SECURITY_CONTROLS.md).
     * @param string  $entityType   Business entity kind (blog, campaign, ...).
     * @param ?int    $entityId     Numeric row id, if applicable.
     * @param ?array  $oldValue     Pre-change snapshot (redacted before persist).
     * @param ?array  $newValue     Post-change snapshot (redacted before persist).
     * @param ?array  $extra        Free-form metadata (redacted before persist).
     * @param ?string $actorType    human|system|bot|service.
     * @param ?string $actorService Slug of the service that produced the event.
     * @param ?string $reason       Free-form justification (approval override, cancel note).
     * @param ?string $requestId    Correlation id from the originating request/job.
     * @param ?int    $jobId        reach_jobs.id when the event is job-driven.
     */
    public function log(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?array $extra = null,
        ?string $actorType = null,
        ?string $actorService = null,
        ?string $reason = null,
        ?string $requestId = null,
        ?int $jobId = null,
    ): void {
        try {
            $req       = service('request');
            $redactor  = Services::secretRedactor();
            $oldSafe   = $oldValue !== null ? $redactor->redact($oldValue) : null;
            $newSafe   = $newValue !== null ? $redactor->redact($newValue) : null;
            $extraSafe = $extra !== null ? $redactor->redact($extra) : null;

            $resolvedRequestId = $requestId
                ?? ($req->reachRequestId ?? null)
                ?? ((string) $req->getHeaderLine('X-Request-Id') !== '' ? $req->getHeaderLine('X-Request-Id') : null);

            $row = [
                'user_id'       => $userId,
                'actor_type'    => $actorType,
                'actor_service' => $actorService,
                'action'        => $action,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'old_value'     => $oldSafe !== null ? json_encode($oldSafe, JSON_UNESCAPED_SLASHES) : null,
                'new_value'     => $newSafe !== null ? json_encode($newSafe, JSON_UNESCAPED_SLASHES) : null,
                'metadata'      => $extraSafe !== null ? json_encode($extraSafe, JSON_UNESCAPED_SLASHES) : null,
                'reason'        => $reason !== null ? substr($reason, 0, 510) : null,
                'request_id'    => $resolvedRequestId !== null ? substr((string) $resolvedRequestId, 0, 64) : null,
                'job_id'        => $jobId,
                'ip_address'    => $req->getIPAddress(),
                'user_agent'    => substr((string) $req->getUserAgent(), 0, 510),
            ];
            (new AuditLogModel())->insert($row);
        } catch (\Throwable $e) {
            log_message('error', 'AuditLogger::log failed: ' . $e->getMessage());
        }

        if ($this->shouldFanOut($action)) {
            try {
                $redactor = Services::secretRedactor();
                Services::consoleAudit()->event('reach.' . $action, [
                    'user_id'       => $userId,
                    'actor_type'    => $actorType,
                    'actor_service' => $actorService,
                    'entity_type'   => $entityType,
                    'entity_id'     => $entityId,
                    'new_value'     => $newValue !== null ? $redactor->redact($newValue) : null,
                    'metadata'      => $extra !== null ? $redactor->redact($extra) : null,
                    'reason'        => $reason,
                    'request_id'    => $requestId,
                    'job_id'        => $jobId,
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
