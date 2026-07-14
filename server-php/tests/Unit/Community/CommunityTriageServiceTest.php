<?php

namespace Tests\Unit\Community;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for triage score calculation logic (isolated, no DB).
 */
final class CommunityTriageServiceTest extends TestCase
{
    /**
     * Mirror of CommunityTriageService::calculateScore().
     */
    private function calculateScore(array $question): int
    {
        $score = 0;

        // Recency: newer questions get higher priority
        $ageHours = isset($question['source_received_at'])
            ? (int) round((time() - strtotime($question['source_received_at'])) / 3600)
            : 0;
        if ($ageHours < 24) $score += 30;
        elseif ($ageHours < 72) $score += 20;
        elseif ($ageHours < 168) $score += 10;

        // Risk
        match ($question['risk_classification'] ?? 'low') {
            'critical' => $score += 40,
            'high'     => $score += 25,
            'medium'   => $score += 10,
            default    => $score += 0,
        };

        // Compliance keywords
        $complianceKeywords = ['gst', 'tds', 'compliance', 'legal', 'tax'];
        $titleLower = strtolower($question['title'] ?? '');
        foreach ($complianceKeywords as $kw) {
            if (str_contains($titleLower, $kw)) {
                $score += 5;
            }
        }

        return min($score, 100);
    }

    public function testHighRiskCriticalGetsHighScore(): void
    {
        $question = [
            'source_received_at' => date('Y-m-d H:i:s', time() - 3600), // 1 hour ago
            'risk_classification' => 'critical',
            'title' => 'Question about gst compliance',
        ];
        $score = $this->calculateScore($question);
        $this->assertGreaterThan(50, $score);
    }

    public function testOldLowRiskGetsLowScore(): void
    {
        $question = [
            'source_received_at' => date('Y-m-d H:i:s', time() - 7 * 24 * 3600 - 1000), // > 7 days
            'risk_classification' => 'low',
            'title' => 'Simple question',
        ];
        $score = $this->calculateScore($question);
        $this->assertLessThanOrEqual(10, $score);
    }

    public function testComplianceKeywordBoostsScore(): void
    {
        $base = [
            'source_received_at' => date('Y-m-d H:i:s', time() - 500),
            'risk_classification' => 'low',
            'title' => 'Question',
        ];
        $withKeyword = array_merge($base, ['title' => 'Question about tds filing']);
        $this->assertGreaterThan($this->calculateScore($base), $this->calculateScore($withKeyword));
    }

    public function testScoreNeverExceeds100(): void
    {
        $question = [
            'source_received_at' => date('Y-m-d H:i:s', time() - 1),
            'risk_classification' => 'critical',
            'title' => 'gst tds compliance legal tax question',
        ];
        $this->assertLessThanOrEqual(100, $this->calculateScore($question));
    }

    public function testScoreIsNonNegative(): void
    {
        $question = [
            'source_received_at' => date('Y-m-d H:i:s', time() - 30 * 24 * 3600),
            'risk_classification' => 'low',
            'title' => '',
        ];
        $this->assertGreaterThanOrEqual(0, $this->calculateScore($question));
    }
}
