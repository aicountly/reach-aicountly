<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityRiskClassification;
use PHPUnit\Framework\TestCase;

final class CommunityRiskClassificationTest extends TestCase
{
    public function testLowDoesNotRequireProfessionalReview(): void
    {
        $this->assertFalse(CommunityRiskClassification::Low->requiresProfessionalReview());
    }

    public function testMediumDoesNotRequireProfessionalReview(): void
    {
        $this->assertFalse(CommunityRiskClassification::Medium->requiresProfessionalReview());
    }

    public function testHighRequiresProfessionalReview(): void
    {
        $this->assertTrue(CommunityRiskClassification::High->requiresProfessionalReview());
    }

    public function testCriticalRequiresProfessionalReview(): void
    {
        $this->assertTrue(CommunityRiskClassification::Critical->requiresProfessionalReview());
    }

    public function testOnlyCriticalRequiresComplianceReview(): void
    {
        $this->assertFalse(CommunityRiskClassification::High->requiresComplianceReview());
        $this->assertTrue(CommunityRiskClassification::Critical->requiresComplianceReview());
    }

    public function testAllLevelsBlockPublicationUntilApproved(): void
    {
        foreach (CommunityRiskClassification::cases() as $level) {
            $this->assertTrue($level->blocksPublicationUntilApproved());
        }
    }

    public function testFromStringCastingWorks(): void
    {
        $level = CommunityRiskClassification::from('medium');
        $this->assertSame(CommunityRiskClassification::Medium, $level);
    }

    public function testAllFourLevelsExist(): void
    {
        $values = array_map(fn($c) => $c->value, CommunityRiskClassification::cases());
        $this->assertContains('low', $values);
        $this->assertContains('medium', $values);
        $this->assertContains('high', $values);
        $this->assertContains('critical', $values);
    }
}
