<?php

namespace Tests\Unit\Community;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for title similarity heuristics (isolated, no DB).
 */
final class CommunityDuplicateDetectionServiceTest extends TestCase
{
    /**
     * Mirrors CommunityDuplicateDetectionService::isSimilarTitle()
     */
    private function isSimilarTitle(string $a, string $b, float $threshold = 0.75): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if ($a === $b) {
            return true;
        }

        similar_text($a, $b, $pct);
        return ($pct / 100) >= $threshold;
    }

    public function testIdenticalTitlesAreSimilar(): void
    {
        $this->assertTrue($this->isSimilarTitle('How to file GST return?', 'How to file GST return?'));
    }

    public function testCompletelyDifferentTitlesAreNotSimilar(): void
    {
        $this->assertFalse($this->isSimilarTitle('How to file GST return?', 'What is AICOUNTLY pricing?'));
    }

    public function testNearlyIdenticalTitlesAreSimilar(): void
    {
        $this->assertTrue($this->isSimilarTitle(
            'How to file GST return online?',
            'How to file GST return online'
        ));
    }

    public function testSlightlyDifferentTitlesCanBeSimilar(): void
    {
        $result = $this->isSimilarTitle(
            'How do I file my GST return?',
            'How do I file my GST returns?'
        );
        $this->assertTrue($result);
    }

    public function testShortVsLongTitleIsNotSimilar(): void
    {
        $this->assertFalse($this->isSimilarTitle('GST', 'What is the procedure to file quarterly GST return for small businesses?'));
    }

    public function testThresholdAffectsResult(): void
    {
        $a = 'GST filing guide';
        $b = 'GST filing guides';
        $strictResult = $this->isSimilarTitle($a, $b, 0.99);
        $lenientResult = $this->isSimilarTitle($a, $b, 0.50);
        $this->assertFalse($strictResult);
        $this->assertTrue($lenientResult);
    }
}
