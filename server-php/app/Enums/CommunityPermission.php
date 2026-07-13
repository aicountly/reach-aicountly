<?php

namespace App\Enums;

enum CommunityPermission: string
{
    case View                   = 'community.view';
    case IntakeCreate           = 'community.intake.create';
    case IntakeImport           = 'community.intake.import';
    case QuestionEdit           = 'community.question.edit';
    case QuestionClassify       = 'community.question.classify';
    case QuestionModerate       = 'community.question.moderate';
    case AnswerGenerate         = 'community.answer.generate';
    case AnswerEdit             = 'community.answer.edit';
    case AnswerReview           = 'community.answer.review';
    case AnswerProfessionalReview = 'community.answer.professional_review';
    case AnswerApprove          = 'community.answer.approve';
    case AnswerSchedule         = 'community.answer.schedule';
    case AnswerPublish          = 'community.answer.publish';
    case AnswerUnpublish        = 'community.answer.unpublish';
    case AnswerRestore          = 'community.answer.restore';
    case AnswerWithdraw         = 'community.answer.withdraw';
    case AnswerOverrideValidation = 'community.answer.override_validation';
    case IdentityManage         = 'community.identity.manage';
    case SettingsManage         = 'community.settings.manage';
    case AnalyticsView          = 'community.analytics.view';
    case AuditView              = 'community.audit.view';
    case EngagementIngest       = 'community.engagement.ingest';
}
