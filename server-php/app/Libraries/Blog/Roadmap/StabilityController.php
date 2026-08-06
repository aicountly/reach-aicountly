<?php

namespace App\Libraries\Blog\Roadmap;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use App\Libraries\Database\SchemaGuard;

/**
 * Score stability, caps, and cooldown enforcement for roadmap optimisation.
 */
class StabilityController
{
    private ?BaseConnection $db = null;

    public function __construct(
        private ?float $minScoreMovement = null,
        private ?int $cooldownDays = null,
        private ?int $maxDailyCandidates = null,
        private ?int $maxWeeklyPublications = null,
    ) {
        $this->minScoreMovement ??= (float) env('BLOG_ROADMAP_MIN_SCORE_MOVEMENT', 5);
        $this->cooldownDays ??= (int) env('BLOG_ROADMAP_COOLDOWN_DAYS', 14);
        $this->maxDailyCandidates ??= (int) env('BLOG_ROADMAP_MAX_DAILY_CANDIDATES', 10);
        $this->maxWeeklyPublications ??= (int) env('BLOG_ROADMAP_MAX_WEEKLY_PUBLICATIONS', 5);
    }

    public function shouldSkipForMovement(float $new, ?float $prev): bool
    {
        if ($prev === null) {
            return false;
        }

        return abs($new - $prev) < $this->minScoreMovement;
    }

    public function applyCooldown(int $candidateId): void
    {
        $until = date('Y-m-d H:i:s', strtotime('+' . $this->cooldownDays . ' days'));
        $this->db()->table('reach_topic_candidates')->where('id', $candidateId)->update([
            'cooldown_until' => $until,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    public function dailyCandidateCap(): int
    {
        return $this->maxDailyCandidates;
    }

    public function weeklyPublicationCap(): int
    {
        return $this->maxWeeklyPublications;
    }

    /**
     * @param array<string,int> $counts
     * @param array<string,int|float> $targetMix percents keyed by stream slug
     */
    public function portfolioOverConcentrated(string $stream, array $counts, array $targetMix): bool
    {
        $total = array_sum($counts);
        if ($total <= 0 || $stream === '') {
            return false;
        }

        $streamKey = $this->normalizeStream($stream);
        $targetKey = $this->normalizeStreamKey($targetMix, $streamKey);
        $targetPct = (float) ($targetMix[$targetKey] ?? 0);
        $actualPct = ((int) ($counts[$streamKey] ?? 0) / $total) * 100.0;
        $margin    = (float) env('BLOG_ROADMAP_PORTFOLIO_OVER_PCT_MARGIN', 15);

        return $actualPct > ($targetPct + $margin);
    }

    public function countWeeklyCreateDecisions(string $sinceDate): int
    {
        if (! SchemaGuard::hasTable($this->db(), 'reach_roadmap_decisions')) {
            return 0;
        }

        return (int) $this->db()->table('reach_roadmap_decisions')
            ->where('decided_for_date >=', $sinceDate)
            ->whereIn('decision', [
                RoadmapDecisionEngine::CREATE_NEW,
                RoadmapDecisionEngine::REFRESH_EXISTING,
                RoadmapDecisionEngine::CREATE_PRODUCT_SUPPORT,
            ])
            ->countAllResults();
    }

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    private function normalizeStream(string $stream): string
    {
        $stream = strtolower(trim($stream));
        return match ($stream) {
            'marketing' => 'marketing',
            'product' => 'product',
            'problem_to_product', 'problem-to-product', 'problem' => 'problem_to_product',
            default => $stream,
        };
    }

    /**
     * @param array<string,int|float> $targetMix
     */
    private function normalizeStreamKey(array $targetMix, string $streamKey): string
    {
        if (array_key_exists($streamKey, $targetMix)) {
            return $streamKey;
        }

        foreach (array_keys($targetMix) as $key) {
            if ($this->normalizeStream((string) $key) === $streamKey) {
                return (string) $key;
            }
        }

        return $streamKey;
    }
}
