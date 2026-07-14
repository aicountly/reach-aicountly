<?php

namespace App\Enums;

/**
 * Phase 5 community permission slugs.
 *
 * All values use the established Reach two-segment format: group.action
 * where group uses underscores to namespace community sub-domains.
 *
 * Previous three-segment form (e.g. community.intake.create) was corrected
 * in this enum — see migration 100103 commentary for the mapping record.
 */
enum CommunityPermission: string
{
    case View                     = 'community.view';

    // Intake sub-domain
    case IntakeCreate             = 'community_intake.create';
    case IntakeImport             = 'community_intake.import';

    // Question sub-domain
    case QuestionEdit             = 'community_question.edit';
    case QuestionClassify         = 'community_question.classify';
    case QuestionModerate         = 'community_question.moderate';

    // Answer sub-domain
    case AnswerGenerate           = 'community_answer.generate';
    case AnswerEdit               = 'community_answer.edit';
    case AnswerReview             = 'community_answer.review';
    case AnswerProfessionalReview = 'community_answer.professional_review';
    case AnswerApprove            = 'community_answer.approve';
    case AnswerSchedule           = 'community_answer.schedule';
    case AnswerPublish            = 'community_answer.publish';
    case AnswerUnpublish          = 'community_answer.unpublish';
    case AnswerRestore            = 'community_answer.restore';
    case AnswerWithdraw           = 'community_answer.withdraw';
    case AnswerOverrideValidation = 'community_answer.override_validation';

    // Identity, settings, analytics, audit, engagement
    case IdentityManage           = 'community_identity.manage';
    case SettingsManage           = 'community_settings.manage';
    case AnalyticsView            = 'community_analytics.view';
    case AuditView                = 'community_audit.view';
    case EngagementIngest         = 'community_engagement.ingest';
}
