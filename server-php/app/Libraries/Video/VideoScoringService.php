<?php

declare(strict_types=1);

namespace App\Libraries\Video;

use App\Libraries\AuditLogger;

/**
 * Scores a video idea across 5 dimensions:
 *   1. search_demand      — keyword volume and intent signals
 *   2. topic_authority    — how well our knowledge base covers this topic
 *   3. content_gap        — unmet demand not covered by existing content
 *   4. audience_relevance — alignment with defined personas
 *   5. competitive_diff   — differentiation from known competitor coverage
 *
 * Each dimension is scored 0–20, giving a maximum score of 100.
 */
class VideoScoringService
{
    public function __construct(
        private readonly VideoIdeaRepository $ideaRepo,
        private readonly AuditLogger         $audit,
    ) {}

    public function scoreIdea(array $idea, array $signals, int $userId): array
    {
        $breakdown = [
            'search_demand'     => $this->scoreSearchDemand($signals),
            'topic_authority'   => $this->scoreTopicAuthority($signals),
            'content_gap'       => $this->scoreContentGap($signals),
            'audience_relevance' => $this->scoreAudienceRelevance($signals),
            'competitive_diff'  => $this->scoreCompetitiveDiff($signals),
        ];

        $total     = array_sum($breakdown);
        $rationale = $this->buildRationale($breakdown, $signals);

        $this->ideaRepo->update((int) $idea['id'], [
            'score_total'     => (int) $total,
            'score_breakdown' => $breakdown,
            'rationale'       => $rationale,
            'status'          => 'ready',
        ]);

        $updated = $this->ideaRepo->findById((int) $idea['id']);
        $this->audit->log($userId, AuditLogger::VIDEO_IDEA_SCORED, 'video_idea', (int) $idea['id'], null, [
            'score_total' => $total,
            'breakdown'   => $breakdown,
        ]);

        return $updated;
    }

    private function scoreSearchDemand(array $signals): int
    {
        $volume = (int) ($signals['keyword_volume'] ?? 0);
        if ($volume >= 10000) return 20;
        if ($volume >= 5000)  return 16;
        if ($volume >= 1000)  return 12;
        if ($volume >= 500)   return 8;
        if ($volume >= 100)   return 4;
        return 2;
    }

    private function scoreTopicAuthority(array $signals): int
    {
        $claimCount   = (int) ($signals['related_claim_count'] ?? 0);
        $citationCount = (int) ($signals['related_citation_count'] ?? 0);
        $kbCoverage    = (bool) ($signals['kb_coverage'] ?? false);

        $score = 0;
        if ($claimCount >= 5)    $score += 8;
        elseif ($claimCount >= 2) $score += 5;
        elseif ($claimCount >= 1) $score += 2;

        if ($citationCount >= 3) $score += 7;
        elseif ($citationCount >= 1) $score += 4;

        if ($kbCoverage) $score += 5;

        return min(20, $score);
    }

    private function scoreContentGap(array $signals): int
    {
        $existingContent = (int) ($signals['existing_content_count'] ?? 0);
        $hasVideo        = (bool) ($signals['has_existing_video'] ?? false);

        if ($hasVideo) return 0;
        if ($existingContent === 0) return 20;
        if ($existingContent <= 2)  return 15;
        if ($existingContent <= 5)  return 10;
        return 5;
    }

    private function scoreAudienceRelevance(array $signals): int
    {
        $personaMatch = (float) ($signals['persona_match_score'] ?? 0.0);
        return (int) round($personaMatch * 20);
    }

    private function scoreCompetitiveDiff(array $signals): int
    {
        $competitorCoverage = (float) ($signals['competitor_coverage_score'] ?? 0.0);
        return (int) round((1.0 - min(1.0, $competitorCoverage)) * 20);
    }

    private function buildRationale(array $breakdown, array $signals): string
    {
        $lines = [];
        $lines[] = "Score breakdown:";
        foreach ($breakdown as $dim => $score) {
            $lines[] = "  - {$dim}: {$score}/20";
        }
        $lines[] = "Total: " . array_sum($breakdown) . "/100";
        return implode("\n", $lines);
    }
}
