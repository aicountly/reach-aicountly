<?php

namespace App\Enums;

enum CommunityModerationFindingType: string
{
    case Spam                    = 'spam';
    case Abuse                   = 'abuse';
    case Harassment              = 'harassment';
    case Profanity               = 'profanity';
    case PersonalData            = 'personal_data';
    case ConfidentialInformation = 'confidential_information';
    case LegalRisk               = 'legal_risk';
    case TaxRisk                 = 'tax_risk';
    case UnsupportedClaims       = 'unsupported_claims';
    case HallucinatedFeatures    = 'hallucinated_features';
    case OutdatedProductBehaviour = 'outdated_product_behaviour';
    case DuplicateQuestion       = 'duplicate_question';
    case DuplicateAnswer         = 'duplicate_answer';
    case PromotionalManipulation = 'promotional_manipulation';
    case ImpersonationRisk       = 'impersonation_risk';
    case UnsafeLinks             = 'unsafe_links';
    case MaliciousHtml           = 'malicious_html';
    case PromptInjection         = 'prompt_injection';
    case ProhibitedContent       = 'prohibited_content';

    public function isAutoBlocking(): bool
    {
        return in_array($this, [
            self::PromptInjection,
            self::MaliciousHtml,
            self::UnsafeLinks,
            self::PersonalData,
            self::ConfidentialInformation,
            self::HallucinatedFeatures,
            self::ImpersonationRisk,
            self::ProhibitedContent,
        ], true);
    }

    public function requiresReview(): bool
    {
        return in_array($this, [
            self::LegalRisk,
            self::TaxRisk,
            self::UnsupportedClaims,
            self::OutdatedProductBehaviour,
        ], true);
    }
}
