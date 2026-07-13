<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;

/**
 * Scores community questions for triage prioritisation.
 *
 * Triage score = weighted sum of genuine factors:
 *   - unanswered age (genuine)
 *   - product relevance
 *   - risk level
 *   - support impact (derived from spam_score inversion)
 *   - compliance keyword weight
 *
 * Score is 0–100. No fabricated popularity factors are included.
 */
class CommunityTriageService
{
    private const WEIGHT_AGE        = 0.25;
    private const WEIGHT_RISK       = 0.30;
    private const WEIGHT_PRODUCT    = 0.20;
    private const WEIGHT_COMPLEXITY = 0.15;
    private const WEIGHT_COMPLIANCE = 0.10;

    public function __construct(
        private readonly CommunityQuestionRepository $repo = new CommunityQuestionRepository()
    ) {}

    /**
     * Score inline (used for manual/synchronous intake).
     */
    public function scoreInline(array $question): array
    {
        $score = $this->computeScore($question);
        $this->persistScore((int) $question['id'], $score);
        return array_merge($question, ['triage_score' => $score]);
    }

    /**
     * Score by question ID (used from jobs).
     */
    public function scoreById(int $questionId): float
    {
        $question = $this->repo->findById($questionId);
        if ($question === null) {
            throw new \RuntimeException("Question #{$questionId} not found for triage");
        }

        $score = $this->computeScore($question);
        $this->persistScore($questionId, $score);

        AuditLogger::record(AuditLogger::COMMUNITY_QUESTION_TRIAGE_SCORED, [
            'question_id'   => $questionId,
            'triage_score'  => $score,
        ]);

        return $score;
    }

    private function computeScore(array $question): float
    {
        $ageScore        = $this->ageScore($question['intake_timestamp'] ?? $question['question_timestamp'] ?? null);
        $riskScore       = $this->riskScore($question['risk_classification'] ?? $question['risk'] ?? 'low');
        $productScore    = empty($question['product']) ? 0.5 : 0.9;
        $complexityScore = (float) ($question['complexity_score'] ?? 0.4);
        $complianceScore = $this->complianceScore($question['title'] ?? '', $question['body'] ?? '');

        $total = ($ageScore        * self::WEIGHT_AGE)
               + ($riskScore       * self::WEIGHT_RISK)
               + ($productScore    * self::WEIGHT_PRODUCT)
               + ($complexityScore * self::WEIGHT_COMPLEXITY)
               + ($complianceScore * self::WEIGHT_COMPLIANCE);

        return round(min(100.0, $total * 100), 3);
    }

    private function ageScore(?string $timestamp): float
    {
        if ($timestamp === null) {
            return 0.5;
        }
        $hours = (time() - strtotime($timestamp)) / 3600;
        // Older unanswered questions score higher
        if ($hours > 168) {
            return 1.0;
        }
        if ($hours > 72) {
            return 0.8;
        }
        if ($hours > 24) {
            return 0.6;
        }
        return 0.3;
    }

    private function riskScore(string $risk): float
    {
        return match ($risk) {
            'critical' => 1.0,
            'high'     => 0.8,
            'medium'   => 0.5,
            default    => 0.2,
        };
    }

    private function complianceScore(string $title, string $body): float
    {
        $text = strtolower($title . ' ' . $body);
        $keywords = ['gst', 'tax', 'compliance', 'audit', 'tds', 'itr', 'penalty'];
        $hits = 0;
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                $hits++;
            }
        }
        return min(1.0, $hits * 0.25);
    }

    private function persistScore(int $questionId, float $score): void
    {
        db_connect()->table('reach_community_questions')
            ->where('id', $questionId)
            ->update(['triage_score' => $score, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
