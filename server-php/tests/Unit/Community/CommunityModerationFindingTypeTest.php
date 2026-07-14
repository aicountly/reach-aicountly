<?php

namespace Tests\Unit\Community;

use App\Enums\CommunityModerationFindingType;
use PHPUnit\Framework\TestCase;

final class CommunityModerationFindingTypeTest extends TestCase
{
    public function testPromptInjectionIsAutoBlocking(): void
    {
        $this->assertTrue(CommunityModerationFindingType::PromptInjection->isAutoBlocking());
    }

    public function testMaliciousHtmlIsAutoBlocking(): void
    {
        $this->assertTrue(CommunityModerationFindingType::MaliciousHtml->isAutoBlocking());
    }

    public function testUnsafeLinksIsAutoBlocking(): void
    {
        $this->assertTrue(CommunityModerationFindingType::UnsafeLinks->isAutoBlocking());
    }

    public function testPersonalDataIsAutoBlocking(): void
    {
        $this->assertTrue(CommunityModerationFindingType::PersonalData->isAutoBlocking());
    }

    public function testLegalRiskRequiresReview(): void
    {
        $this->assertTrue(CommunityModerationFindingType::LegalRisk->requiresReview());
    }

    public function testTaxRiskRequiresReview(): void
    {
        $this->assertTrue(CommunityModerationFindingType::TaxRisk->requiresReview());
    }

    public function testUnsupportedClaimsRequiresReview(): void
    {
        $this->assertTrue(CommunityModerationFindingType::UnsupportedClaims->requiresReview());
    }

    public function testSpamDoesNotRequireReview(): void
    {
        $this->assertFalse(CommunityModerationFindingType::Spam->requiresReview());
    }

    public function testFromStringWorks(): void
    {
        $type = CommunityModerationFindingType::from('spam');
        $this->assertSame(CommunityModerationFindingType::Spam, $type);
    }

    public function testFromStringForPromptInjection(): void
    {
        $type = CommunityModerationFindingType::from('prompt_injection');
        $this->assertSame(CommunityModerationFindingType::PromptInjection, $type);
    }

    public function testAllTypeValuesAreNonEmpty(): void
    {
        foreach (CommunityModerationFindingType::cases() as $type) {
            $this->assertNotEmpty($type->value);
        }
    }
}
