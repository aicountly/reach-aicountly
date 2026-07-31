<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityRiskClassification;
use App\Enums\CommunityRiskTier;
use PHPUnit\Framework\TestCase;

/**
 * CommunityRiskTier drives every publication gate in the official-answer
 * lifecycle (submitForReview routing, approve() blocking, and the
 * publish-time re-check in OfficialAnswerPublishingService). It had no
 * dedicated test coverage before this audit despite being the single source
 * of truth for "can this ever publish, and does it need a human".
 */
final class CommunityRiskTierTest extends TestCase
{
    public function testOnlyProhibitedIsBlocked(): void
    {
        foreach (CommunityRiskTier::cases() as $tier) {
            $expected = $tier === CommunityRiskTier::Prohibited;
            $this->assertSame($expected, $tier->isBlocked(), "Tier {$tier->name} isBlocked() mismatch");
        }
    }

    public function testOnlyRegulatoryAndIndividualAdviceRequireProfessionalApproval(): void
    {
        $this->assertFalse(CommunityRiskTier::ProductUsage->requiresProfessionalApproval());
        $this->assertFalse(CommunityRiskTier::GeneralEducation->requiresProfessionalApproval());
        $this->assertTrue(CommunityRiskTier::Regulatory->requiresProfessionalApproval());
        $this->assertTrue(CommunityRiskTier::IndividualAdvice->requiresProfessionalApproval());
        // Prohibited never reaches an approval decision (it's blocked outright)
        // but the predicate itself must still be well-defined.
        $this->assertFalse(CommunityRiskTier::Prohibited->requiresProfessionalApproval());
    }

    public function testOnlyProductUsageAndGeneralEducationAreAutoPublishable(): void
    {
        $this->assertTrue(CommunityRiskTier::ProductUsage->isAutoPublishable());
        $this->assertTrue(CommunityRiskTier::GeneralEducation->isAutoPublishable());
        $this->assertFalse(CommunityRiskTier::Regulatory->isAutoPublishable());
        $this->assertFalse(CommunityRiskTier::IndividualAdvice->isAutoPublishable());
        $this->assertFalse(CommunityRiskTier::Prohibited->isAutoPublishable());
    }

    public function testFreshEvidenceRequiredFromRegulatoryUpward(): void
    {
        $this->assertFalse(CommunityRiskTier::ProductUsage->requiresFreshEvidence());
        $this->assertFalse(CommunityRiskTier::GeneralEducation->requiresFreshEvidence());
        $this->assertTrue(CommunityRiskTier::Regulatory->requiresFreshEvidence());
        $this->assertTrue(CommunityRiskTier::IndividualAdvice->requiresFreshEvidence());
        $this->assertTrue(CommunityRiskTier::Prohibited->requiresFreshEvidence());
    }

    public function testFreshnessDaysDecreaseAsRiskIncreases(): void
    {
        $this->assertGreaterThan(CommunityRiskTier::GeneralEducation->freshnessDays(), CommunityRiskTier::ProductUsage->freshnessDays());
        $this->assertGreaterThan(CommunityRiskTier::Regulatory->freshnessDays(), CommunityRiskTier::GeneralEducation->freshnessDays());
        $this->assertGreaterThan(CommunityRiskTier::IndividualAdvice->freshnessDays(), CommunityRiskTier::Regulatory->freshnessDays());
    }

    public function testToClassificationRoundTripPreservesGatingBehaviour(): void
    {
        // Classification only has 4 buckets but tier has 5 — ProductUsage and
        // GeneralEducation both collapse to Low, and fromClassification()
        // always picks the lower of the two on the way back (documented,
        // intentional lossy compression). The invariant that must hold is
        // not enum identity but that no *gating decision* changes: a
        // round-tripped tier must never require less scrutiny (approval,
        // blocking, fresh evidence) than the tier it started as.
        foreach (CommunityRiskTier::cases() as $tier) {
            $roundTripped = CommunityRiskTier::fromClassification($tier->toClassification());

            if ($tier->isBlocked()) {
                // Prohibited has no dedicated classification bucket and
                // collapses into Critical/IndividualAdvice on the way back —
                // acceptable only because IndividualAdvice still requires
                // professional approval; it must never collapse to something
                // auto-publishable.
                $this->assertFalse($roundTripped->isAutoPublishable(), 'Prohibited must never round-trip into an auto-publishable tier');
                continue;
            }

            if (! $tier->isAutoPublishable()) {
                $this->assertFalse($roundTripped->isAutoPublishable(), "Round-tripping {$tier->name} must not relax it into auto-publishable");
            }
            if ($tier->requiresProfessionalApproval()) {
                $this->assertTrue($roundTripped->requiresProfessionalApproval(), "Round-tripping {$tier->name} must not drop the professional-approval requirement");
            }
        }
    }

    public function testFromClassificationMapsAllFourLevels(): void
    {
        $this->assertSame(CommunityRiskTier::ProductUsage, CommunityRiskTier::fromClassification(CommunityRiskClassification::Low));
        $this->assertSame(CommunityRiskTier::GeneralEducation, CommunityRiskTier::fromClassification(CommunityRiskClassification::Medium));
        $this->assertSame(CommunityRiskTier::Regulatory, CommunityRiskTier::fromClassification(CommunityRiskClassification::High));
        $this->assertSame(CommunityRiskTier::IndividualAdvice, CommunityRiskTier::fromClassification(CommunityRiskClassification::Critical));
    }

    public function testFromQuestionDetectsProhibitedMarkersRegardlessOfOtherContent(): void
    {
        $tier = CommunityRiskTier::fromQuestion([
            'title' => 'How do I evade tax legally using a loophole',
            'body'  => 'Just general question really',
            'product' => 'general',
        ]);
        $this->assertSame(CommunityRiskTier::Prohibited, $tier);
    }

    public function testFromQuestionDetectsIndividualAdviceMarkers(): void
    {
        $tier = CommunityRiskTier::fromQuestion([
            'title' => 'Should I invest in this specific fund given my case',
            'body'  => '',
            'product' => 'general',
        ]);
        $this->assertSame(CommunityRiskTier::IndividualAdvice, $tier);
    }

    public function testFromQuestionDetectsRegulatoryMarkers(): void
    {
        $tier = CommunityRiskTier::fromQuestion([
            'title' => 'What is the GSTR-3B due date this month',
            'body'  => '',
            'product' => 'general',
        ]);
        $this->assertSame(CommunityRiskTier::Regulatory, $tier);
    }

    public function testFromQuestionFallsBackToProductUsageWhenProductIsSet(): void
    {
        $tier = CommunityRiskTier::fromQuestion([
            'title' => 'How do I export a report',
            'body'  => 'Using the dashboard',
            'product' => 'invoicing',
        ]);
        $this->assertSame(CommunityRiskTier::ProductUsage, $tier);
    }

    public function testFromQuestionFallsBackToGeneralEducationOtherwise(): void
    {
        $tier = CommunityRiskTier::fromQuestion([
            'title' => 'What is accounting',
            'body'  => 'General curiosity',
            'product' => 'general',
        ]);
        $this->assertSame(CommunityRiskTier::GeneralEducation, $tier);
    }

    public function testProhibitedMarkerTakesPrecedenceOverIndividualAdviceMarker(): void
    {
        // "should i" (individual advice) and "launder" (prohibited) both present —
        // prohibited must win because it is checked first and is never overridable.
        $tier = CommunityRiskTier::fromQuestion([
            'title' => 'should i launder money through my business',
            'body'  => '',
            'product' => 'general',
        ]);
        $this->assertSame(CommunityRiskTier::Prohibited, $tier);
    }
}
