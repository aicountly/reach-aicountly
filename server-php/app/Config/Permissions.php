<?php

namespace Config;

/**
 * Registry of Reach permission slugs and their groupings.
 *
 * A permission is a dotted string `<group>.<action>`. A role or user grant
 * of `*` means "all permissions". A group wildcard like `blog.*` grants
 * every permission in the group.
 *
 * Backend enforcement lives in App\Filters\PermissionFilter + PermissionService.
 * Frontend uses the same slugs via /v1/me `permissions` and the usePermission hook.
 */
final class Permissions
{
    /** Dashboard */
    public const DASHBOARD_VIEW = 'dashboard.view';

    /** Blog */
    public const BLOG_VIEW      = 'blog.view';
    public const BLOG_CREATE    = 'blog.create';
    public const BLOG_EDIT      = 'blog.edit';
    public const BLOG_SUBMIT    = 'blog.submit';
    public const BLOG_APPROVE   = 'blog.approve';
    public const BLOG_SCHEDULE  = 'blog.schedule';
    public const BLOG_PUBLISH   = 'blog.publish';
    public const BLOG_UNPUBLISH = 'blog.unpublish';

    /** Campaign */
    public const CAMPAIGN_VIEW     = 'campaign.view';
    public const CAMPAIGN_CREATE   = 'campaign.create';
    public const CAMPAIGN_EDIT     = 'campaign.edit';
    public const CAMPAIGN_APPROVE  = 'campaign.approve';
    public const CAMPAIGN_DISPATCH = 'campaign.dispatch';

    /** Social */
    public const SOCIAL_VIEW     = 'social.view';
    public const SOCIAL_CREATE   = 'social.create';
    public const SOCIAL_EDIT     = 'social.edit';
    public const SOCIAL_APPROVE  = 'social.approve';
    public const SOCIAL_DISPATCH = 'social.dispatch';

    /** Email */
    public const EMAIL_VIEW     = 'email.view';
    public const EMAIL_CREATE   = 'email.create';
    public const EMAIL_EDIT     = 'email.edit';
    public const EMAIL_APPROVE  = 'email.approve';
    public const EMAIL_DISPATCH = 'email.dispatch';

    /** WhatsApp */
    public const WHATSAPP_VIEW     = 'whatsapp.view';
    public const WHATSAPP_CREATE   = 'whatsapp.create';
    public const WHATSAPP_EDIT     = 'whatsapp.edit';
    public const WHATSAPP_APPROVE  = 'whatsapp.approve';
    public const WHATSAPP_DISPATCH = 'whatsapp.dispatch';

    /** Leads */
    public const LEAD_VIEW   = 'lead.view';
    public const LEAD_MANAGE = 'lead.manage';
    public const LEAD_EXPORT = 'lead.export';

    /** Approvals */
    public const APPROVAL_VIEW     = 'approval.view';
    public const APPROVAL_DECIDE   = 'approval.decide';
    public const APPROVAL_OVERRIDE = 'approval.override';

    /** Marketing bot */
    public const BOT_VIEW      = 'bot.view';
    public const BOT_DISPATCH  = 'bot.dispatch';
    public const BOT_CONFIGURE = 'bot.configure';

    /** Jobs */
    public const JOB_VIEW   = 'job.view';
    public const JOB_RETRY  = 'job.retry';
    public const JOB_CANCEL = 'job.cancel';

    /** Settings / integrations / audit / analytics */
    public const SETTINGS_VIEW      = 'settings.view';
    public const SETTINGS_MANAGE    = 'settings.manage';
    public const INTEGRATION_VIEW   = 'integration.view';
    public const INTEGRATION_MANAGE = 'integration.manage';
    public const AUDIT_VIEW         = 'audit.view';
    public const ANALYTICS_VIEW     = 'analytics.view';

    // ── Phase 1: Knowledge Foundation ────────────────────────────────────────

    /** Knowledge (cross-entity) */
    public const KNOWLEDGE_VIEW    = 'knowledge.view';
    public const KNOWLEDGE_CREATE  = 'knowledge.create';
    public const KNOWLEDGE_EDIT    = 'knowledge.edit';
    public const KNOWLEDGE_SUBMIT  = 'knowledge.submit';
    public const KNOWLEDGE_APPROVE = 'knowledge.approve';
    public const KNOWLEDGE_ARCHIVE = 'knowledge.archive';

    /** Products */
    public const PRODUCT_VIEW   = 'product.view';
    public const PRODUCT_MANAGE = 'product.manage';

    /** Personas */
    public const PERSONA_VIEW   = 'persona.view';
    public const PERSONA_MANAGE = 'persona.manage';

    /** Industries */
    public const INDUSTRY_VIEW   = 'industry.view';
    public const INDUSTRY_MANAGE = 'industry.manage';

    /** Search intents */
    public const INTENT_VIEW   = 'intent.view';
    public const INTENT_MANAGE = 'intent.manage';

    /** Sources */
    public const SOURCE_VIEW    = 'source.view';
    public const SOURCE_MANAGE  = 'source.manage';
    public const SOURCE_APPROVE = 'source.approve';

    /** Citations */
    public const CITATION_VIEW    = 'citation.view';
    public const CITATION_MANAGE  = 'citation.manage';
    public const CITATION_APPROVE = 'citation.approve';

    /** Claims */
    public const CLAIM_VIEW    = 'claim.view';
    public const CLAIM_MANAGE  = 'claim.manage';
    public const CLAIM_APPROVE = 'claim.approve';

    /** Brand rules */
    public const BRAND_RULES_VIEW    = 'brand_rules.view';
    public const BRAND_RULES_MANAGE  = 'brand_rules.manage';
    public const BRAND_RULES_APPROVE = 'brand_rules.approve';

    /** Content policies */
    public const CONTENT_POLICY_VIEW    = 'content_policy.view';
    public const CONTENT_POLICY_MANAGE  = 'content_policy.manage';
    public const CONTENT_POLICY_APPROVE = 'content_policy.approve';

    // ── Phase 2: Unified Content Studio ─────────────────────────────────────

    /** Content items */
    public const CONTENT_VIEW           = 'content.view';
    public const CONTENT_CREATE         = 'content.create';
    public const CONTENT_EDIT           = 'content.edit';
    public const CONTENT_SUBMIT         = 'content.submit';
    public const CONTENT_REVIEW         = 'content.review';
    public const CONTENT_APPROVE        = 'content.approve';
    public const CONTENT_REJECT         = 'content.reject';
    public const CONTENT_SCHEDULE_PERM  = 'content.schedule';
    public const CONTENT_ARCHIVE        = 'content.archive';

    /** Content versions */
    public const CONTENT_VERSION_VIEW   = 'content_version.view';
    public const CONTENT_VERSION_CREATE = 'content_version.create';

    /** Content comments */
    public const CONTENT_COMMENT_VIEW    = 'content_comment.view';
    public const CONTENT_COMMENT_CREATE  = 'content_comment.create';
    public const CONTENT_COMMENT_RESOLVE = 'content_comment.resolve';
    public const CONTENT_COMMENT_DELETE  = 'content_comment.delete';

    /** Content assignments */
    public const CONTENT_ASSIGNMENT_VIEW   = 'content_assignment.view';
    public const CONTENT_ASSIGNMENT_MANAGE = 'content_assignment.manage';

    /** Content validations */
    public const CONTENT_VALIDATION_VIEW   = 'content_validation.view';
    public const CONTENT_VALIDATION_MANAGE = 'content_validation.manage';
    public const CONTENT_VALIDATION_WAIVE  = 'content_validation.waive';

    /** Daily marketing packs */
    public const DAILY_PACK_VIEW   = 'daily_pack.view';
    public const DAILY_PACK_CREATE = 'daily_pack.create';
    public const DAILY_PACK_MANAGE = 'daily_pack.manage';

    /** Content schedules */
    public const CONTENT_SCHEDULE_VIEW   = 'content_schedule.view';
    public const CONTENT_SCHEDULE_CREATE = 'content_schedule.create';
    public const CONTENT_SCHEDULE_CANCEL = 'content_schedule.cancel';

    /** Publication targets */
    public const PUBLICATION_TARGET_VIEW   = 'publication_target.view';
    public const PUBLICATION_TARGET_MANAGE = 'publication_target.manage';

    // ── Phase 3: AI Generation, Validation, and Control Centre ───────────────

    /** AI generation — who may trigger AI drafts */
    public const AI_VIEW            = 'ai.view';
    public const AI_GENERATE        = 'ai.generate';
    public const AI_CANCEL          = 'ai.cancel';
    public const AI_BULK_GENERATE   = 'ai.bulk_generate';

    /** AI provider management — infrastructure team only */
    public const AI_PROVIDER_VIEW   = 'ai_provider.view';
    public const AI_PROVIDER_MANAGE = 'ai_provider.manage';

    /** AI model management */
    public const AI_MODEL_VIEW   = 'ai_model.view';
    public const AI_MODEL_MANAGE = 'ai_model.manage';

    /** AI routing management */
    public const AI_ROUTING_VIEW   = 'ai_routing.view';
    public const AI_ROUTING_MANAGE = 'ai_routing.manage';

    /** AI prompt governance */
    public const AI_PROMPT_VIEW    = 'ai_prompt.view';
    public const AI_PROMPT_MANAGE  = 'ai_prompt.manage';
    public const AI_PROMPT_APPROVE = 'ai_prompt.approve';

    /** AI generation request visibility */
    public const AI_GENERATION_VIEW   = 'ai_generation.view';
    public const AI_GENERATION_MANAGE = 'ai_generation.manage';

    // =========================================================================
    // Phase 4 — Publishing, SEO/AEO, Structured Data, Knowledge Base
    // =========================================================================

    /** Publishing — deployment lifecycle */
    public const PUBLISHING_VIEW               = 'publishing.view';
    public const PUBLISHING_PUBLISH            = 'publishing.publish';
    public const PUBLISHING_SCHEDULE           = 'publishing.schedule';
    public const PUBLISHING_UNPUBLISH          = 'publishing.unpublish';
    public const PUBLISHING_ROLLBACK           = 'publishing.rollback';
    public const PUBLISHING_VERIFY             = 'publishing.verify';
    public const PUBLISHING_MANAGE_CONNECTIONS = 'publishing.manage_connections';
    public const PUBLISHING_MANAGE_PROFILES    = 'publishing.manage_profiles';

    /** SEO profile management */
    public const SEO_VIEW   = 'seo.view';
    public const SEO_MANAGE = 'seo.manage';
    public const SEO_REVIEW = 'seo.review';

    /** AEO profile management */
    public const AEO_VIEW   = 'aeo.view';
    public const AEO_MANAGE = 'aeo.manage';
    public const AEO_REVIEW = 'aeo.review';

    /** Structured data management */
    public const STRUCTURED_DATA_VIEW   = 'structured_data.view';
    public const STRUCTURED_DATA_MANAGE = 'structured_data.manage';
    public const STRUCTURED_DATA_REVIEW = 'structured_data.review';

    /** Knowledge-base publishing and profiles */
    public const KB_PUBLISHING_VIEW    = 'kb_publishing.view';
    public const KB_PUBLISHING_PUBLISH = 'kb_publishing.publish';
    public const KB_PUBLISHING_MANAGE  = 'kb_publishing.manage';

    /** AI grounding visibility */
    public const AI_GROUNDING_VIEW = 'ai_grounding.view';

    /** AI usage and budget */
    public const AI_USAGE_VIEW     = 'ai_usage.view';
    public const AI_BUDGET_VIEW    = 'ai_budget.view';
    public const AI_BUDGET_MANAGE  = 'ai_budget.manage';

    /** AI validation — viewing and waiving findings */
    public const AI_VALIDATION_VIEW  = 'ai_validation.view';
    public const AI_VALIDATION_WAIVE = 'ai_validation.waive';

    /**
     * @return array<string, string[]> group => permission list
     */
    public static function groups(): array
    {
        return [
            'dashboard'   => [self::DASHBOARD_VIEW],
            'blog'        => [
                self::BLOG_VIEW, self::BLOG_CREATE, self::BLOG_EDIT, self::BLOG_SUBMIT,
                self::BLOG_APPROVE, self::BLOG_SCHEDULE, self::BLOG_PUBLISH, self::BLOG_UNPUBLISH,
            ],
            'campaign'    => [
                self::CAMPAIGN_VIEW, self::CAMPAIGN_CREATE, self::CAMPAIGN_EDIT,
                self::CAMPAIGN_APPROVE, self::CAMPAIGN_DISPATCH,
            ],
            'social'      => [
                self::SOCIAL_VIEW, self::SOCIAL_CREATE, self::SOCIAL_EDIT,
                self::SOCIAL_APPROVE, self::SOCIAL_DISPATCH,
            ],
            'email'       => [
                self::EMAIL_VIEW, self::EMAIL_CREATE, self::EMAIL_EDIT,
                self::EMAIL_APPROVE, self::EMAIL_DISPATCH,
            ],
            'whatsapp'    => [
                self::WHATSAPP_VIEW, self::WHATSAPP_CREATE, self::WHATSAPP_EDIT,
                self::WHATSAPP_APPROVE, self::WHATSAPP_DISPATCH,
            ],
            'lead'        => [self::LEAD_VIEW, self::LEAD_MANAGE, self::LEAD_EXPORT],
            'approval'    => [self::APPROVAL_VIEW, self::APPROVAL_DECIDE, self::APPROVAL_OVERRIDE],
            'bot'         => [self::BOT_VIEW, self::BOT_DISPATCH, self::BOT_CONFIGURE],
            'job'         => [self::JOB_VIEW, self::JOB_RETRY, self::JOB_CANCEL],
            'settings'    => [self::SETTINGS_VIEW, self::SETTINGS_MANAGE],
            'integration' => [self::INTEGRATION_VIEW, self::INTEGRATION_MANAGE],
            'audit'          => [self::AUDIT_VIEW],
            'analytics'      => [self::ANALYTICS_VIEW],
            // Phase 1 knowledge groups
            'knowledge'      => [
                self::KNOWLEDGE_VIEW, self::KNOWLEDGE_CREATE, self::KNOWLEDGE_EDIT,
                self::KNOWLEDGE_SUBMIT, self::KNOWLEDGE_APPROVE, self::KNOWLEDGE_ARCHIVE,
            ],
            'product'        => [self::PRODUCT_VIEW, self::PRODUCT_MANAGE],
            'persona'        => [self::PERSONA_VIEW, self::PERSONA_MANAGE],
            'industry'       => [self::INDUSTRY_VIEW, self::INDUSTRY_MANAGE],
            'intent'         => [self::INTENT_VIEW, self::INTENT_MANAGE],
            'source'         => [self::SOURCE_VIEW, self::SOURCE_MANAGE, self::SOURCE_APPROVE],
            'citation'       => [self::CITATION_VIEW, self::CITATION_MANAGE, self::CITATION_APPROVE],
            'claim'          => [self::CLAIM_VIEW, self::CLAIM_MANAGE, self::CLAIM_APPROVE],
            'brand_rules'    => [self::BRAND_RULES_VIEW, self::BRAND_RULES_MANAGE, self::BRAND_RULES_APPROVE],
            'content_policy' => [self::CONTENT_POLICY_VIEW, self::CONTENT_POLICY_MANAGE, self::CONTENT_POLICY_APPROVE],
            // Phase 2 content studio groups
            'content'            => [
                self::CONTENT_VIEW, self::CONTENT_CREATE, self::CONTENT_EDIT,
                self::CONTENT_SUBMIT, self::CONTENT_REVIEW, self::CONTENT_APPROVE,
                self::CONTENT_REJECT, self::CONTENT_SCHEDULE_PERM, self::CONTENT_ARCHIVE,
            ],
            'content_version'    => [self::CONTENT_VERSION_VIEW, self::CONTENT_VERSION_CREATE],
            'content_comment'    => [
                self::CONTENT_COMMENT_VIEW, self::CONTENT_COMMENT_CREATE,
                self::CONTENT_COMMENT_RESOLVE, self::CONTENT_COMMENT_DELETE,
            ],
            'content_assignment' => [self::CONTENT_ASSIGNMENT_VIEW, self::CONTENT_ASSIGNMENT_MANAGE],
            'content_validation' => [
                self::CONTENT_VALIDATION_VIEW, self::CONTENT_VALIDATION_MANAGE, self::CONTENT_VALIDATION_WAIVE,
            ],
            'daily_pack'         => [self::DAILY_PACK_VIEW, self::DAILY_PACK_CREATE, self::DAILY_PACK_MANAGE],
            'content_schedule'   => [
                self::CONTENT_SCHEDULE_VIEW, self::CONTENT_SCHEDULE_CREATE, self::CONTENT_SCHEDULE_CANCEL,
            ],
            'publication_target' => [self::PUBLICATION_TARGET_VIEW, self::PUBLICATION_TARGET_MANAGE],
            // Phase 3: AI groups
            'ai'             => [self::AI_VIEW, self::AI_GENERATE, self::AI_CANCEL, self::AI_BULK_GENERATE],
            'ai_provider'    => [self::AI_PROVIDER_VIEW, self::AI_PROVIDER_MANAGE],
            'ai_model'       => [self::AI_MODEL_VIEW, self::AI_MODEL_MANAGE],
            'ai_routing'     => [self::AI_ROUTING_VIEW, self::AI_ROUTING_MANAGE],
            'ai_prompt'      => [self::AI_PROMPT_VIEW, self::AI_PROMPT_MANAGE, self::AI_PROMPT_APPROVE],
            'ai_generation'  => [self::AI_GENERATION_VIEW, self::AI_GENERATION_MANAGE],
            'ai_grounding'   => [self::AI_GROUNDING_VIEW],
            'ai_usage'       => [self::AI_USAGE_VIEW],
            'ai_budget'      => [self::AI_BUDGET_VIEW, self::AI_BUDGET_MANAGE],
            'ai_validation'  => [self::AI_VALIDATION_VIEW, self::AI_VALIDATION_WAIVE],
            // Phase 4: Publishing & SEO groups
            'publishing'       => [
                self::PUBLISHING_VIEW, self::PUBLISHING_PUBLISH, self::PUBLISHING_SCHEDULE,
                self::PUBLISHING_UNPUBLISH, self::PUBLISHING_ROLLBACK, self::PUBLISHING_VERIFY,
                self::PUBLISHING_MANAGE_CONNECTIONS, self::PUBLISHING_MANAGE_PROFILES,
            ],
            'seo'              => [self::SEO_VIEW, self::SEO_MANAGE, self::SEO_REVIEW],
            'aeo'              => [self::AEO_VIEW, self::AEO_MANAGE, self::AEO_REVIEW],
            'structured_data'  => [self::STRUCTURED_DATA_VIEW, self::STRUCTURED_DATA_MANAGE, self::STRUCTURED_DATA_REVIEW],
            'kb_publishing'    => [self::KB_PUBLISHING_VIEW, self::KB_PUBLISHING_PUBLISH, self::KB_PUBLISHING_MANAGE],
        ];
    }

    /**
     * @return string[] flat list of every defined permission
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::groups() as $perms) {
            foreach ($perms as $p) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function isKnown(string $perm): bool
    {
        return $perm === '*' || str_ends_with($perm, '.*') || in_array($perm, self::all(), true);
    }
}
