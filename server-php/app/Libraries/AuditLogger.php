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
        // Phase 5 community events
        'community.',
        // Phase 6 video events
        'video.',
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

    // =========================================================================
    // Phase 4 — Publishing audit events
    // =========================================================================
    public const PUBLISHING_QUEUED              = 'publishing.queued';
    public const PUBLISHING_ACCEPTED            = 'publishing.accepted';
    public const PUBLISHING_FAILED              = 'publishing.failed';
    public const PUBLISHING_VERIFIED            = 'publishing.verified';
    public const PUBLISHING_ROLLED_BACK         = 'publishing.rolled_back';
    public const PUBLISHING_ROLLBACK_FAILED     = 'publishing.rollback_failed';
    public const PUBLISHING_CANCELLED           = 'publishing.cancelled';
    public const PUBLISHING_RETRY_SCHEDULED     = 'publishing.retry_scheduled';
    public const PUBLISHING_MAX_RETRIES         = 'publishing.max_retries_reached';
    public const PUBLISHING_HEALTH_CHECKED      = 'publishing.health_checked';
    public const PUBLISHING_REFRESH_REQUESTED   = 'publishing.refresh_requested';
    public const PUBLISHING_SITEMAP_VERIFIED    = 'publishing.sitemap_verified';
    public const PUBLISHING_INDEXING_READY      = 'publishing.indexing_ready';
    public const PUBLISHING_RECONCILIATION_ERR  = 'publishing.reconciliation_error';
    public const PUBLISHING_RECONCILIATION_DISC = 'publishing.reconciliation_discrepancy';

    // Phase 4 — SEO/AEO audit events
    public const SEO_PROFILE_UPDATED            = 'seo.profile_updated';
    public const SEO_REVIEW_STARTED             = 'seo.review_started';
    public const SEO_REVIEWED                   = 'seo.reviewed';
    public const SEO_BLOCKED                    = 'seo.blocked';
    public const AEO_PROFILE_UPDATED            = 'aeo.profile_updated';
    public const AEO_REVIEWED                   = 'aeo.reviewed';

    // Phase 4 — Structured data audit events
    public const STRUCTURED_DATA_GENERATED      = 'structured_data.generated';
    public const STRUCTURED_DATA_VALIDATED      = 'structured_data.validated';
    public const STRUCTURED_DATA_BLOCKED        = 'structured_data.blocked';
    public const STRUCTURED_DATA_REVIEWED       = 'structured_data.reviewed';

    // Phase 4 — Blog/KB publication profile events
    public const BLOG_PROFILE_UPDATED           = 'blog_profile.updated';
    public const KB_PROFILE_UPDATED             = 'kb_profile.updated';
    public const KB_STRUCTURE_VALIDATED         = 'kb_structure.validated';

    // =========================================================================
    // Phase 5 — Community and Official Q&A audit events
    // =========================================================================
    public const COMMUNITY_QUESTION_INTAKE              = 'community.question.intake';
    public const COMMUNITY_QUESTION_IMPORT              = 'community.question.import';
    public const COMMUNITY_QUESTION_CLASSIFIED          = 'community.question.classified';
    public const COMMUNITY_QUESTION_TRIAGE_SCORED       = 'community.question.triage_scored';
    public const COMMUNITY_QUESTION_ASSIGNED            = 'community.question.assigned';
    public const COMMUNITY_QUESTION_DUPLICATE_DETECTED  = 'community.question.duplicate_detected';
    public const COMMUNITY_QUESTION_DUPLICATE_MERGED    = 'community.question.duplicate_merged';
    public const COMMUNITY_QUESTION_MODERATED           = 'community.question.moderated';
    public const COMMUNITY_QUESTION_ARCHIVED            = 'community.question.archived';

    public const COMMUNITY_ANSWER_GENERATION_REQUESTED  = 'community.answer.generation_requested';
    public const COMMUNITY_ANSWER_GENERATION_STARTED    = 'community.answer.generation_started';
    public const COMMUNITY_ANSWER_GENERATION_COMPLETED  = 'community.answer.generation_completed';
    public const COMMUNITY_ANSWER_GENERATION_FAILED     = 'community.answer.generation_failed';
    public const COMMUNITY_ANSWER_DRAFT_CREATED         = 'community.answer.draft_created';
    public const COMMUNITY_ANSWER_VERSION_CREATED       = 'community.answer.version_created';
    public const COMMUNITY_ANSWER_SOURCE_ATTACHED       = 'community.answer.source_attached';
    public const COMMUNITY_ANSWER_VALIDATION_PASSED     = 'community.answer.validation_passed';
    public const COMMUNITY_ANSWER_VALIDATION_FAILED     = 'community.answer.validation_failed';
    public const COMMUNITY_ANSWER_MODERATION_FINDING    = 'community.answer.moderation_finding';
    public const COMMUNITY_ANSWER_MODERATION_OVERRIDE   = 'community.answer.moderation_override';
    public const COMMUNITY_ANSWER_REVIEW_REQUESTED      = 'community.answer.review_requested';
    public const COMMUNITY_ANSWER_REVIEW_COMPLETED      = 'community.answer.review_completed';
    public const COMMUNITY_ANSWER_PROFESSIONAL_REVIEW   = 'community.answer.professional_review_requested';
    public const COMMUNITY_ANSWER_APPROVAL_SUBMITTED    = 'community.answer.approval_submitted';
    public const COMMUNITY_ANSWER_APPROVED              = 'community.answer.approved';
    public const COMMUNITY_ANSWER_REJECTED              = 'community.answer.rejected';
    public const COMMUNITY_ANSWER_CHANGES_REQUESTED     = 'community.answer.changes_requested';
    public const COMMUNITY_ANSWER_SCHEDULED             = 'community.answer.scheduled';
    public const COMMUNITY_ANSWER_PUBLISHING            = 'community.answer.publishing';
    public const COMMUNITY_ANSWER_PUBLISHED             = 'community.answer.published';
    public const COMMUNITY_ANSWER_VERIFICATION_PASSED   = 'community.answer.verification_passed';
    public const COMMUNITY_ANSWER_VERIFICATION_FAILED   = 'community.answer.verification_failed';
    public const COMMUNITY_ANSWER_CHECKSUM_MISMATCH     = 'community.answer.checksum_mismatch';
    public const COMMUNITY_ANSWER_RETRY                 = 'community.answer.retry';
    public const COMMUNITY_ANSWER_RECONCILIATION        = 'community.answer.reconciliation';
    public const COMMUNITY_ANSWER_CORRECTION_STARTED    = 'community.answer.correction_started';
    public const COMMUNITY_ANSWER_CORRECTED             = 'community.answer.corrected';
    public const COMMUNITY_ANSWER_UNPUBLISHED           = 'community.answer.unpublished';
    public const COMMUNITY_ANSWER_WITHDRAWN             = 'community.answer.withdrawn';
    public const COMMUNITY_ANSWER_RESTORED              = 'community.answer.restored';

    public const COMMUNITY_IDENTITY_CREATED             = 'community.identity.created';
    public const COMMUNITY_IDENTITY_UPDATED             = 'community.identity.updated';
    public const COMMUNITY_IDENTITY_DEACTIVATED         = 'community.identity.deactivated';

    public const COMMUNITY_SETTINGS_CHANGED             = 'community.settings.changed';
    public const COMMUNITY_ENGAGEMENT_INGESTED          = 'community.engagement.ingested';
    public const COMMUNITY_ENGAGEMENT_BOT_FILTERED      = 'community.engagement.bot_filtered';
    public const COMMUNITY_PUBLISHING_CHECKSUM_MISMATCH = 'community.publishing.checksum_mismatch';
    public const COMMUNITY_PUBLISHING_ROLLBACK          = 'community.publishing.rollback';

    // Additional Phase 5 events used by controllers and jobs
    public const COMMUNITY_QUESTION_INGESTED            = 'community.question.ingested';
    public const COMMUNITY_QUESTION_STATUS_CHANGED      = 'community.question.status_changed';
    public const COMMUNITY_ANSWER_EDITED                = 'community.answer.edited';
    public const COMMUNITY_ANSWER_APPROVAL_REJECTED     = 'community.answer.approval_rejected';
    public const COMMUNITY_ANSWER_VERIFICATION_RUN      = 'community.answer.verification_run';
    public const COMMUNITY_ANALYTICS_RECONCILED         = 'community.analytics.reconciled';
    public const COMMUNITY_SPACE_CREATED                = 'community.space.created';
    public const COMMUNITY_SPACE_UPDATED                = 'community.space.updated';
    public const COMMUNITY_MODERATION_FINDING_RESOLVED  = 'community.moderation.finding_resolved';
    public const COMMUNITY_MODERATION_FINDING_ESCALATED = 'community.moderation.finding_escalated';
    public const COMMUNITY_MODERATION_RUN               = 'community.moderation.run';
    public const COMMUNITY_DEPLOYMENT_RETRIED           = 'community.deployment.retried';
    public const COMMUNITY_ENGAGEMENT_RECORDED          = 'community.engagement.recorded';

    // =========================================================================
    // Phase 6 — Video Content Automation audit events
    // =========================================================================

    public const VIDEO_IDEA_CREATED           = 'video.idea.created';
    public const VIDEO_IDEA_SCORED            = 'video.idea.scored';
    public const VIDEO_IDEA_ACCEPTED          = 'video.idea.accepted';
    public const VIDEO_IDEA_REJECTED          = 'video.idea.rejected';
    public const VIDEO_IDEA_ARCHIVED          = 'video.idea.archived';
    public const VIDEO_IDEA_CONVERTED         = 'video.idea.converted';
    public const VIDEO_IDEA_DUPLICATE_FLAGGED = 'video.idea.duplicate_flagged';

    public const VIDEO_PROJECT_CREATED     = 'video.project.created';
    public const VIDEO_PROJECT_UPDATED     = 'video.project.updated';
    public const VIDEO_PROJECT_CANCELLED   = 'video.project.cancelled';
    public const VIDEO_PROJECT_WITHDRAWN   = 'video.project.withdrawn';

    public const VIDEO_SCRIPT_GENERATED         = 'video.script.generated';
    public const VIDEO_SCRIPT_GENERATION_FAILED = 'video.script.generation_failed';
    public const VIDEO_SCRIPT_SUBMITTED         = 'video.script.submitted';
    public const VIDEO_SCRIPT_APPROVED          = 'video.script.approved';
    public const VIDEO_SCRIPT_REJECTED          = 'video.script.rejected';
    public const VIDEO_SCRIPT_CHANGES_REQUESTED = 'video.script.changes_requested';
    public const VIDEO_SCRIPT_VERSION_CREATED   = 'video.script.version_created';

    public const VIDEO_RENDER_QUEUED       = 'video.render.queued';
    public const VIDEO_RENDER_STARTED      = 'video.render.started';
    public const VIDEO_RENDER_COMPLETED    = 'video.render.completed';
    public const VIDEO_RENDER_FAILED       = 'video.render.failed';
    public const VIDEO_RENDER_CANCELLED    = 'video.render.cancelled';
    public const VIDEO_RENDER_RETRIED      = 'video.render.retried';
    public const VIDEO_RENDER_DEAD_LETTERED = 'video.render.dead_lettered';

    public const VIDEO_PUBLISH_QUEUED     = 'video.publish.queued';
    public const VIDEO_PUBLISH_STARTED    = 'video.publish.started';
    public const VIDEO_PUBLISH_PUBLISHED  = 'video.publish.published';
    public const VIDEO_PUBLISH_FAILED     = 'video.publish.failed';
    public const VIDEO_PUBLISH_CANCELLED  = 'video.publish.cancelled';
    public const VIDEO_PUBLISH_RETRIED    = 'video.publish.retried';

    public const VIDEO_CONNECTION_CREATED       = 'video.connection.created';
    public const VIDEO_CONNECTION_REVOKED       = 'video.connection.revoked';
    public const VIDEO_CONNECTION_HEALTH_CHECKED = 'video.connection.health_checked';

    public const VIDEO_ASSET_UPLOADED   = 'video.asset.uploaded';
    public const VIDEO_ASSET_VALIDATED  = 'video.asset.validated';
    public const VIDEO_ASSET_REJECTED   = 'video.asset.rejected';
    public const VIDEO_ASSET_DELETED    = 'video.asset.deleted';

    public const VIDEO_PROVIDER_CALLBACK_RECEIVED        = 'video.provider.callback_received';
    public const VIDEO_PROVIDER_CALLBACK_VERIFIED        = 'video.provider.callback_verified';
    public const VIDEO_PROVIDER_CALLBACK_REPLAY_REJECTED = 'video.provider.callback_replay_rejected';
    public const VIDEO_PROVIDER_CALLBACK_INVALID_SIGNATURE = 'video.provider.callback_invalid_signature';

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

    /**
     * Static convenience wrapper for controller audit events.
     * Automatically derives entityType from the action prefix (community.* → 'community',
     * everything else → 'publishing'). Never throws — failures are logged only.
     */
    public static function record(string $action, array $context = [], ?int $actorId = null): void
    {
        try {
            $entityType = str_starts_with($action, 'community.') ? 'community' : 'publishing';
            (new self())->log(
                userId:       $actorId,
                action:       $action,
                entityType:   $entityType,
                entityId:     $context['content_item_id'] ?? $context['deployment_id'] ?? null,
                extra:        $context ?: null,
                actorType:    $actorId !== null ? 'human' : 'system',
                actorService: 'reach:api',
            );
        } catch (\Throwable $e) {
            log_message('error', 'AuditLogger::record failed: ' . $e->getMessage());
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
