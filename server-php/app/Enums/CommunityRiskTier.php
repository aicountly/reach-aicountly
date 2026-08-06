<?php

namespace App\Enums;

/**
 * Numeric risk tier for community content.
 *
 * The tier drives publication gating. The older four-value
 * CommunityRiskClassification is retained because it is written into database
 * CHECK constraints and existing rows; the two are kept in sync through
 * toClassification()/fromClassification().
 */
enum CommunityRiskTier: int
{
    /** AICOUNTLY product usage and low-risk navigation. */
    case ProductUsage = 0;

    /** General accounting and business education. */
    case GeneralEducation = 1;

    /** Current GST, income tax, company law, labour law, payroll, regulatory. */
    case Regulatory = 2;

    /** Individualised legal, tax, litigation, financial or investment advice. */
    case IndividualAdvice = 3;

    /** Unsafe, unlawful, deceptive, privacy-invasive or otherwise prohibited. */
    case Prohibited = 4;

    public function label(): string
    {
        return match ($this) {
            self::ProductUsage     => 'Product usage',
            self::GeneralEducation => 'General education',
            self::Regulatory       => 'Regulatory / statutory',
            self::IndividualAdvice => 'Individualised advice',
            self::Prohibited       => 'Prohibited',
        };
    }

    /** Tiers 2 and 3 can never publish without a qualified human approval. */
    public function requiresProfessionalApproval(): bool
    {
        return $this === self::Regulatory || $this === self::IndividualAdvice;
    }

    /** Tier 4 is never publishable — it is routed to moderation instead. */
    public function isBlocked(): bool
    {
        return $this === self::Prohibited;
    }

    /**
     * Whether this tier may auto-publish once verification succeeds, subject to
     * the relevant feature flag. Tiers 2-4 always require a human.
     */
    public function isAutoPublishable(): bool
    {
        return $this === self::ProductUsage || $this === self::GeneralEducation;
    }

    /** Answers at this tier must carry current authoritative evidence. */
    public function requiresFreshEvidence(): bool
    {
        return $this->value >= self::Regulatory->value;
    }

    /** Days a verification remains valid before re-verification is due. */
    public function freshnessDays(): int
    {
        return match ($this) {
            self::ProductUsage     => 365,
            self::GeneralEducation => 270,
            self::Regulatory       => 90,
            self::IndividualAdvice => 30,
            self::Prohibited       => 0,
        };
    }

    public function toClassification(): CommunityRiskClassification
    {
        return match ($this) {
            self::ProductUsage, self::GeneralEducation => CommunityRiskClassification::Low,
            self::Regulatory                           => CommunityRiskClassification::High,
            self::IndividualAdvice, self::Prohibited   => CommunityRiskClassification::Critical,
        };
    }

    public static function fromClassification(string|CommunityRiskClassification $classification): self
    {
        $value = $classification instanceof CommunityRiskClassification
            ? $classification
            : (CommunityRiskClassification::tryFrom($classification) ?? CommunityRiskClassification::Low);

        return match ($value) {
            CommunityRiskClassification::Low      => self::ProductUsage,
            CommunityRiskClassification::Medium   => self::GeneralEducation,
            CommunityRiskClassification::High     => self::Regulatory,
            CommunityRiskClassification::Critical => self::IndividualAdvice,
        };
    }

    /**
     * The higher of this tier and a later assessment.
     *
     * fromQuestion() establishes a floor and the generation agent may raise it
     * — it has read the drafted content, which the question-time
     * classification had not — but never lower it, or a draft could talk its
     * own publication gate away. Callers that accept a model-supplied risk
     * level must route it through here rather than assigning it.
     */
    public function raisedTo(self $assessed): self
    {
        return $assessed->value > $this->value ? $assessed : $this;
    }

    /**
     * Derive an initial tier from question metadata. This is the floor: the
     * generation agent may raise it but never lower it.
     */
    public static function fromQuestion(array $question): self
    {
        $text = strtolower(trim(($question['title'] ?? '') . ' ' . ($question['body'] ?? '')));

        foreach (self::PROHIBITED_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return self::Prohibited;
            }
        }
        foreach (self::INDIVIDUAL_ADVICE_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return self::IndividualAdvice;
            }
        }
        foreach (self::REGULATORY_MARKERS as $marker) {
            if (str_contains($text, $marker)) {
                return self::Regulatory;
            }
        }

        $product = strtolower((string) ($question['product'] ?? ''));
        if ($product !== '' && $product !== 'general') {
            return self::ProductUsage;
        }

        return self::GeneralEducation;
    }

    private const PROHIBITED_MARKERS = [
        'evade tax', 'tax evasion', 'fake invoice', 'forge', 'launder', 'bribe',
        'hack into', 'bypass verification', 'fake gst number', 'backdate',
    ];

    private const INDIVIDUAL_ADVICE_MARKERS = [
        'should i', 'my case', 'in my situation', 'advise me', 'my company should',
        'received a notice', 'my litigation', 'should we invest', 'which investment',
    ];

    private const REGULATORY_MARKERS = [
        'gst', 'gstr', 'income tax', 'itr', 'tds', 'tcs', 'companies act',
        'roc filing', 'mca', 'labour law', 'provident fund', 'esic',
        'statutory', 'due date', 'penalty', 'notification', 'circular',
    ];
}
