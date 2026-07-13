<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Central registry of every enum-like string used across the API.
 *
 * Keeping these in one place prevents drift between:
 *   - migrations (CHECK constraints)
 *   - models (allowedFields validation)
 *   - controllers (payload validation)
 *   - services / policies
 *
 * Every controller that accepts one of these string values MUST validate
 * against the list here rather than hard-coding the accepted set inline.
 * When adding a new value, also update the corresponding migration's CHECK
 * constraint and the RBAC / audit docs.
 */
class Enums extends BaseConfig
{
    /**
     * Blog post workflow states — kept in sync with BlogController::WORKFLOW.
     */
    public array $blogStatus = [
        'idea', 'draft', 'seo_review', 'internal_review',
        'approved', 'scheduled', 'published', 'rejected', 'archived',
    ];

    /**
     * Approval subject types.
     */
    public array $approvalSubject = [
        'blog', 'campaign', 'social_post', 'email_campaign',
        'whatsapp_campaign', 'landing_page',
    ];

    /**
     * Approval outcomes.
     */
    public array $approvalDecision = ['pending', 'approved', 'rejected'];

    /**
     * Campaign lifecycle states.
     */
    public array $campaignStatus = [
        'draft', 'approved', 'scheduled', 'live', 'paused',
        'completed', 'rejected', 'archived',
    ];

    /**
     * Actor kinds tracked on actor-aware tables.
     */
    public array $actorType = ['human', 'system', 'bot', 'service'];

    /**
     * Job lifecycle states — matches reach_jobs.status CHECK constraint.
     */
    public array $jobStatus = [
        'pending', 'reserved', 'processing', 'completed',
        'failed', 'retry_wait', 'dead_letter', 'cancelled',
    ];

    /**
     * Rate-limit scope strings (matches RateLimits config keys).
     */
    public array $rateLimitScope = ['ip', 'user', 'ip+user', 'token'];

    /**
     * User permission override modes (reach_user_permissions.mode).
     */
    public array $permissionMode = ['grant', 'deny'];

    // ── Phase 1: Knowledge Foundation enums ──────────────────────────────────

    /**
     * Knowledge entity statuses — matches CHECK constraints on all knowledge tables.
     */
    public array $knowledgeStatus = [
        'draft', 'needs_review', 'approved', 'rejected', 'deprecated', 'archived',
    ];

    /**
     * Feature availability — matches reach_product_features.availability CHECK.
     */
    public array $featureAvailability = [
        'available', 'limited', 'beta', 'planned', 'deprecated', 'unknown',
    ];

    /**
     * Product claim risk levels — matches reach_product_claims.risk_level CHECK.
     */
    public array $claimRisk = ['low', 'medium', 'high', 'critical'];

    /**
     * Search intent types — matches reach_search_intents.intent_type CHECK.
     */
    public array $intentType = [
        'informational', 'navigational', 'transactional', 'commercial',
    ];

    /**
     * Search intent funnel stages — matches reach_search_intents.funnel_stage CHECK.
     */
    public array $funnelStage = ['top', 'middle', 'bottom'];

    /**
     * Evidence types — matches reach_evidence.evidence_type CHECK.
     */
    public array $evidenceType = [
        'benchmark', 'case_study', 'whitepaper', 'demo', 'changelog',
        'support_article', 'third_party_report', 'internal',
    ];

    /**
     * Source types — matches reach_sources.source_type CHECK.
     */
    public array $sourceType = [
        'official_docs', 'press_release', 'third_party', 'community', 'internal',
    ];

    /**
     * Brand rule types — matches reach_brand_rules.rule_type CHECK.
     */
    public array $brandRuleType = [
        'preferred_name', 'avoid_term', 'tone', 'trademark', 'competitor_mention',
    ];

    /**
     * Content policy types — matches reach_content_policies.policy_type CHECK.
     */
    public array $contentPolicyType = ['legal', 'brand', 'accuracy', 'format', 'channel'];

    /**
     * Product alias sources — matches reach_product_aliases.source CHECK.
     */
    public array $aliasSource = ['legacy_code', 'user_defined', 'brand'];

    // ── Phase 2: Unified Content Studio enums ────────────────────────────────

    /**
     * Content types — matches reach_content_items.content_type CHECK.
     */
    public array $contentType = [
        'blog', 'knowledge_base', 'community', 'video', 'social_post',
        'email', 'whatsapp', 'sms', 'landing_page', 'announcement',
        'webinar', 'case_study', 'refresh', 'template', 'brief', 'other',
    ];

    /**
     * Content workflow statuses — matches reach_content_items.workflow_status CHECK.
     */
    public array $contentWorkflowStatus = [
        'idea', 'brief', 'draft', 'validation_pending', 'review_pending',
        'changes_requested', 'approved', 'scheduled', 'ready_for_publication',
        'published', 'archived', 'rejected', 'refresh_due',
    ];

    /**
     * Content approval statuses — matches reach_content_items.approval_status CHECK.
     */
    public array $contentApprovalStatus = [
        'not_required', 'pending', 'approved', 'rejected', 'changes_requested',
    ];

    /**
     * Content validation statuses — matches reach_content_items.validation_status CHECK.
     */
    public array $contentValidationStatus = [
        'not_run', 'passed', 'failed', 'warnings', 'waived', 'skipped',
    ];

    /**
     * Content publication statuses — matches reach_content_items.publication_status CHECK.
     */
    public array $contentPublicationStatus = [
        'not_scheduled', 'scheduled', 'ready', 'blocked', 'cancelled',
    ];

    /**
     * Content risk levels — matches reach_content_items.risk_level CHECK.
     */
    public array $contentRiskLevel = ['low', 'medium', 'high', 'critical'];

    /**
     * Validation types — matches reach_content_validations.validation_type CHECK.
     */
    public array $contentValidationType = [
        'product_claim', 'fact', 'seo', 'brand', 'tone', 'grammar',
        'legal', 'compliance', 'plagiarism', 'accessibility', 'readability',
        'link', 'format', 'custom',
    ];

    /**
     * Assignment roles — matches reach_content_assignments.role CHECK.
     */
    public array $assignmentRole = [
        'owner', 'writer', 'reviewer', 'subject_matter_reviewer',
        'compliance_reviewer', 'publisher', 'observer',
    ];

    /**
     * Publication channels — matches reach_content_publication_targets.channel CHECK.
     */
    public array $publicationChannel = [
        'aicountly_website', 'youtube', 'linkedin', 'twitter', 'facebook',
        'instagram', 'email_newsletter', 'whatsapp_broadcast', 'sms_blast',
        'partner_portal', 'docs_site', 'webinar_platform', 'other',
    ];

    /**
     * Approval stages — matches reach_approvals.stage CHECK.
     */
    public array $approvalStage = [
        'editorial_review', 'subject_matter_review', 'compliance_review', 'final_approval',
    ];

    /**
     * Daily pack statuses — matches reach_daily_marketing_packs.pack_status CHECK.
     */
    public array $packStatus = ['draft', 'in_progress', 'ready', 'completed', 'cancelled'];

    // ── Phase 3: AI Generation Engine ────────────────────────────────────────

    /**
     * AI provider status — matches reach_ai_providers.status CHECK.
     */
    public array $aiProviderStatus = ['draft', 'enabled', 'disabled', 'deprecated'];

    /**
     * AI model approval status — matches reach_ai_models.approval_status CHECK.
     */
    public array $aiModelApprovalStatus = ['pending', 'approved', 'deprecated'];

    /**
     * AI generation request status — matches reach_ai_generation_requests.status CHECK.
     */
    public array $aiGenerationStatus = [
        'pending', 'grounding', 'queued', 'processing', 'validating',
        'completed', 'failed', 'cancelled', 'blocked',
    ];

    /**
     * AI generation run status — matches reach_ai_generation_runs.status CHECK.
     */
    public array $aiRunStatus = ['pending', 'running', 'completed', 'failed', 'cancelled'];

    /**
     * AI prompt template/version status.
     */
    public array $aiPromptStatus = ['draft', 'needs_review', 'approved', 'rejected', 'deprecated'];

    /**
     * AI task types — what kind of generation is being requested.
     */
    public array $aiTaskType = [
        'draft_generation', 'section_regeneration', 'title_suggestions',
        'meta_description', 'summary_generation', 'email_subject_suggestions',
        'hashtag_generation', 'cta_generation', 'content_expansion',
        'tone_adjustment', 'seo_optimisation', 'translation_prep',
    ];

    /**
     * AI content types — the 16 supported structured output schemas.
     */
    public array $aiContentType = [
        'blog_post', 'landing_page', 'email_campaign', 'social_post',
        'whatsapp_campaign', 'sms_campaign', 'push_notification',
        'product_description', 'case_study', 'whitepaper',
        'press_release', 'video_script', 'podcast_script',
        'ad_copy', 'knowledge_article', 'generic',
    ];

    /**
     * AI validation finding severity.
     */
    public array $aiValidationSeverity = ['critical', 'high', 'warning', 'info'];

    /**
     * AI validation finding status.
     */
    public array $aiValidationFindingStatus = ['passed', 'failed', 'warning', 'not_applicable', 'waived'];

    /**
     * AI budget scope types — matches reach_ai_budgets.scope_type CHECK.
     */
    public array $aiBudgetScopeType = [
        'global', 'provider', 'model', 'content_type', 'task_type', 'user',
    ];

    /**
     * AI budget period types — matches reach_ai_budgets.period_type CHECK.
     */
    public array $aiBudgetPeriodType = ['daily', 'monthly'];

    /**
     * Return true if the value is a member of the named enum. Unknown enum
     * names return false so typos in controllers fail closed.
     */
    public function isValid(string $enumName, mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }
        $list = $this->{$enumName} ?? null;
        if (! is_array($list)) {
            return false;
        }
        return in_array($value, $list, true);
    }

    /**
     * Return the enum list or throw for unknown names.
     */
    public function values(string $enumName): array
    {
        $list = $this->{$enumName} ?? null;
        if (! is_array($list)) {
            throw new \InvalidArgumentException("Unknown enum: $enumName");
        }
        return $list;
    }
}
