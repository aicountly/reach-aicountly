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
        // Phase 1 knowledge events
        'knowledge.',
        // Phase 2 content events
        'content.',
        'publication.',
        'daily_pack.',
        // Phase 3 AI events
        'ai.',
        'generation.',
        'ai_prompt.',
        'ai_budget.',
    ];

    /**
     * Phase 1 knowledge audit event slugs.
     * Controllers and services MUST use these constants rather than inline strings.
     */
    public const KNOWLEDGE_CREATED       = 'knowledge.created';
    public const KNOWLEDGE_UPDATED       = 'knowledge.updated';
    public const KNOWLEDGE_DELETED       = 'knowledge.deleted';
    public const KNOWLEDGE_STATUS_CHANGE = 'knowledge.status_changed';
    public const KNOWLEDGE_SUBMITTED     = 'knowledge.submitted';
    public const KNOWLEDGE_APPROVED      = 'knowledge.approved';
    public const KNOWLEDGE_REJECTED      = 'knowledge.rejected';
    public const KNOWLEDGE_ARCHIVED      = 'knowledge.archived';
    public const KNOWLEDGE_IMPORT        = 'knowledge.taxonomy_imported';
    public const KNOWLEDGE_RELATION_ADD  = 'knowledge.relation_added';
    public const KNOWLEDGE_RELATION_DEL  = 'knowledge.relation_removed';
    public const KNOWLEDGE_CLAIM_HIGH_RISK_BLOCKED = 'knowledge.claim_high_risk_blocked';

    /**
     * Phase 2 content audit event slugs.
     */
    public const CONTENT_CREATED              = 'content.created';
    public const CONTENT_UPDATED              = 'content.updated';
    public const CONTENT_ARCHIVED             = 'content.archived';
    public const CONTENT_STATUS_CHANGED       = 'content.status_changed';
    public const CONTENT_SUBMITTED            = 'content.submitted';
    public const CONTENT_APPROVED             = 'content.approved';
    public const CONTENT_REJECTED             = 'content.rejected';
    public const CONTENT_CHANGES_REQUESTED    = 'content.changes_requested';
    public const CONTENT_ASSIGNED             = 'content.assigned';
    public const CONTENT_UNASSIGNED           = 'content.unassigned';
    public const CONTENT_COMMENTED            = 'content.commented';
    public const CONTENT_COMMENT_DELETED      = 'content.comment_deleted';
    public const CONTENT_COMMENT_RESOLVED     = 'content.comment_resolved';
    public const CONTENT_VALIDATION_STORED    = 'content.validation_stored';
    public const CONTENT_VALIDATION_WAIVED    = 'content.validation_waived';
    public const CONTENT_SCHEDULED            = 'content.scheduled';
    public const CONTENT_SCHEDULE_CANCELLED   = 'content.schedule_cancelled';
    public const CONTENT_MAPPED               = 'content.mapped';
    public const CONTENT_VERSION_CREATED      = 'content.version_created';
    public const PUBLICATION_ATTEMPT_CREATED  = 'publication.attempt_created';
    public const PUBLICATION_ATTEMPT_BLOCKED  = 'publication.attempt_blocked';
    public const DAILY_PACK_GENERATED         = 'daily_pack.generated';
    public const DAILY_PACK_ITEM_ASSIGNED     = 'daily_pack.item_assigned';
    public const DAILY_PACK_APPROVED          = 'daily_pack.approved';

    /**
     * Phase 3 AI audit event slugs.
     */
    public const AI_GENERATION_REQUESTED   = 'ai.generation_requested';
    public const AI_GENERATION_STARTED     = 'ai.generation_started';
    public const AI_GENERATION_COMPLETED   = 'ai.generation_completed';
    public const AI_GENERATION_FAILED      = 'ai.generation_failed';
    public const AI_GENERATION_CANCELLED   = 'ai.generation_cancelled';
    public const AI_GENERATION_BLOCKED     = 'ai.generation_blocked';
    public const AI_GROUNDING_BUILT        = 'ai.grounding_built';
    public const AI_GROUNDING_SNAPSHOT     = 'ai.grounding_snapshot_stored';
    public const AI_ARTIFACT_STORED        = 'ai.artifact_stored';
    public const AI_ARTIFACT_SCHEMA_FAILED = 'ai.artifact_schema_validation_failed';
    public const AI_PROVIDER_HEALTH_CHANGED = 'ai.provider_health_changed';
    public const AI_PROVIDER_CIRCUIT_OPEN  = 'ai.provider_circuit_opened';
    public const AI_PROVIDER_CIRCUIT_CLOSE = 'ai.provider_circuit_closed';
    public const AI_PROMPT_CREATED         = 'ai_prompt.created';
    public const AI_PROMPT_VERSION_CREATED = 'ai_prompt.version_created';
    public const AI_PROMPT_VERSION_APPROVED= 'ai_prompt.version_approved';
    public const AI_PROMPT_VERSION_REJECTED= 'ai_prompt.version_rejected';
    public const AI_BUDGET_WARNED          = 'ai_budget.warning_threshold_reached';
    public const AI_BUDGET_HARD_BLOCKED    = 'ai_budget.hard_limit_blocked';
    public const AI_BUDGET_UPDATED         = 'ai_budget.updated';
    public const AI_VALIDATION_RUN_STARTED = 'ai.validation_run_started';
    public const AI_VALIDATION_RUN_DONE    = 'ai.validation_run_completed';
    public const AI_VALIDATION_FINDING_WAIVED = 'ai.validation_finding_waived';
    public const AI_USAGE_RECORDED         = 'ai.usage_recorded';
    public const AI_ROUTE_SELECTED         = 'ai.route_selected';
    public const AI_FALLBACK_TRIGGERED     = 'ai.fallback_triggered';
    public const AI_MODEL_ENABLED          = 'ai.model_enabled';
    public const AI_MODEL_DISABLED         = 'ai.model_disabled';
    public const AI_PROVIDER_ENABLED       = 'ai.provider_enabled';
    public const AI_PROVIDER_DISABLED      = 'ai.provider_disabled';

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
