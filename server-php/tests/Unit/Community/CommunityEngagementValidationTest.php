<?php

namespace Tests\Unit\Community;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for engagement event validation heuristics (isolated, no DB).
 */
final class CommunityEngagementValidationTest extends TestCase
{
    private function passesBasicValidation(array $row): bool
    {
        if (empty($row['answer_external_id'])) {
            return false;
        }
        if (!empty($row['dedup_key'])) {
            return true;
        }
        $trustedPlatforms = ['reach_sdk', 'aicountly_com', 'reach_api'];
        return in_array($row['source_platform'] ?? '', $trustedPlatforms, true);
    }

    public function testEventWithDedupKeyIsValid(): void
    {
        $this->assertTrue($this->passesBasicValidation([
            'answer_external_id' => 'uuid-123',
            'dedup_key' => 'dk-abc',
            'source_platform' => 'reach_api',
        ]));
    }

    public function testEventWithoutAnswerUuidIsInvalid(): void
    {
        $this->assertFalse($this->passesBasicValidation([
            'answer_external_id' => '',
            'dedup_key' => 'dk-abc',
        ]));
    }

    public function testTrustedPlatformWithoutDedupKeyIsValid(): void
    {
        $this->assertTrue($this->passesBasicValidation([
            'answer_external_id' => 'uuid-456',
            'source_platform' => 'aicountly_com',
        ]));
    }

    public function testUnknownPlatformWithoutDedupKeyIsInvalid(): void
    {
        $this->assertFalse($this->passesBasicValidation([
            'answer_external_id' => 'uuid-789',
            'source_platform' => 'some_scraper',
        ]));
    }

    public function testAllThreeTrustedPlatformsPassValidation(): void
    {
        foreach (['reach_sdk', 'aicountly_com', 'reach_api'] as $platform) {
            $this->assertTrue($this->passesBasicValidation([
                'answer_external_id' => 'uuid',
                'source_platform' => $platform,
            ]), "Platform '{$platform}' should be trusted");
        }
    }
}
