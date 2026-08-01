<?php

namespace App\Libraries\Blog\Roadmap;

use App\Libraries\Support\PgBoolean;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Pure scoring math plus optional persistence for topic candidates.
 */
class TopicScoringService
{
    /** @var array<string,string> */
    private const DIRECT_SIGNALS = [
        'product_priority'    => 'product_priority',
        'audience_problem'    => 'audience_problem',
        'content_gap'         => 'content_gap',
        'seasonality'         => 'seasonality',
        'internal_link_value' => 'internal_link_value',
    ];

    /** @var array<string,string> */
    private const DEDUCTION_SIGNALS = [
        'cannibalisation',
        'near_duplicate',
        'weak_evidence',
        'product_feature_unavailable',
        'recently_generated',
        'portfolio_over_concentration',
    ];

    private ?BaseConnection $db = null;

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $signals
     * @return array<string,mixed>
     */
    public function scoreCandidate(array $candidate, array $signals, ?ScoringWeights $weights = null): array
    {
        $weights ??= ScoringWeights::defaults();
        $weights->assertValid();

        $missingSignals = [];
        $inputs         = ['signals' => $signals, 'candidate_id' => $candidate['id'] ?? null];

        $searchNorm = $this->compositeNorm($signals, [
            'impressions'      => ['cap' => 10000.0],
            'clicks'           => ['cap' => 1000.0],
            'ctr'              => ['cap' => 1.0, 'percent' => false],
            'avg_position'     => ['invert_position' => true],
            'organic_sessions' => ['cap' => 5000.0],
        ], $missingSignals);

        $conversionNorm = $this->compositeNorm($signals, [
            'leads'          => ['cap' => 100.0],
            'conversions'    => ['cap' => 50.0],
            'product_visits' => ['cap' => 1000.0],
        ], $missingSignals);

        $factorNormals = [
            'search_opportunity'   => $searchNorm,
            'product_priority'     => $this->directNorm($signals, 'product_priority', $missingSignals),
            'audience_problem'     => $this->directNorm($signals, 'audience_problem', $missingSignals),
            'conversion_potential' => $conversionNorm,
            'content_gap'          => $this->directNorm($signals, 'content_gap', $missingSignals),
            'seasonality'          => $this->directNorm($signals, 'seasonality', $missingSignals),
            'internal_link_value'  => $this->directNorm($signals, 'internal_link_value', $missingSignals),
            'evidence_readiness'   => $this->evidenceNorm($signals, $candidate, $missingSignals),
        ];

        $factors = [];
        $rawTotal = 0.0;
        foreach ($factorNormals as $factor => $norm) {
            $weighted = round($norm * $weights->weightForFactor($factor), 2);
            $factors[$factor] = $weighted;
            $rawTotal += $weighted;
        }

        $deductions = [];
        $deductionTotal = 0.0;
        foreach (self::DEDUCTION_SIGNALS as $key) {
            if (! empty($signals[$key])) {
                $penalty = (float) ($weights->deductions[$key] ?? 0);
                if ($penalty > 0) {
                    $deductions[$key] = $penalty;
                    $deductionTotal += $penalty;
                }
            }
        }
        $deductionTotal = round($deductionTotal, 2);

        $totalScore = max(0.0, round($rawTotal - $deductionTotal, 2));
        $inputs['missing_signals'] = array_values(array_unique($missingSignals));

        return [
            'total_score'     => $totalScore,
            'factors'         => $factors,
            'deductions'      => $deductions,
            'deduction_total' => $deductionTotal,
            'weights'         => $weights->toArray(),
            'inputs'          => $inputs,
        ];
    }

    /**
     * @param array<string,mixed> $score
     */
    public function persistScore(int $candidateId, array $score, string $forDate): int
    {
        $db = $this->db();

        $db->table('reach_topic_scores')
            ->where('topic_candidate_id', $candidateId)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        $trend7  = $this->trendDelta($candidateId, $forDate, 7, (float) $score['total_score']);
        $trend28 = $this->trendDelta($candidateId, $forDate, 28, (float) $score['total_score']);

        $factors = $score['factors'] ?? [];
        $row = [
            'topic_candidate_id'   => $candidateId,
            'total_score'          => $score['total_score'],
            'search_opportunity'   => $factors['search_opportunity'] ?? 0,
            'product_priority'     => $factors['product_priority'] ?? 0,
            'audience_problem'     => $factors['audience_problem'] ?? 0,
            'conversion_potential' => $factors['conversion_potential'] ?? 0,
            'content_gap'          => $factors['content_gap'] ?? 0,
            'seasonality'          => $factors['seasonality'] ?? 0,
            'internal_link_value'  => $factors['internal_link_value'] ?? 0,
            'evidence_readiness'   => $factors['evidence_readiness'] ?? 0,
            'deductions_json'      => json_encode($score['deductions'] ?? [], JSON_UNESCAPED_SLASHES),
            'deduction_total'      => $score['deduction_total'] ?? 0,
            'weights_json'         => json_encode($score['weights'] ?? [], JSON_UNESCAPED_SLASHES),
            'inputs_json'          => json_encode($score['inputs'] ?? [], JSON_UNESCAPED_SLASHES),
            'trend_7d'             => $trend7,
            'trend_28d'            => $trend28,
            'scored_for_date'      => $forDate,
            'is_current'           => true,
            'created_at'           => date('Y-m-d H:i:s'),
        ];

        $db->table('reach_topic_scores')->insert($row);
        $scoreId = (int) $db->insertID();

        $db->table('reach_topic_candidates')->where('id', $candidateId)->update([
            'status'         => 'scored',
            'last_scored_at' => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $scoreId;
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,array<string,mixed>> $parts
     * @param list<string> $missingSignals
     */
    private function compositeNorm(array $signals, array $parts, array &$missingSignals): float
    {
        $values = [];
        foreach ($parts as $key => $opts) {
            if (! array_key_exists($key, $signals) || $signals[$key] === null || $signals[$key] === '') {
                $missingSignals[] = $key;
                continue;
            }
            $raw = (float) $signals[$key];
            if (isset($opts['invert_position'])) {
                $values[] = max(0.0, min(1.0, 1.0 - (($raw - 1.0) / 99.0)));
                continue;
            }
            $cap = (float) ($opts['cap'] ?? 1.0);
            if (! empty($opts['percent']) && $raw > 1.0) {
                $raw = $raw / 100.0;
            }
            $values[] = max(0.0, min(1.0, $raw / max(1.0, $cap)));
        }

        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param array<string,mixed> $signals
     * @param list<string> $missingSignals
     */
    private function directNorm(array $signals, string $key, array &$missingSignals): float
    {
        if (! array_key_exists($key, $signals) || $signals[$key] === null || $signals[$key] === '') {
            $missingSignals[] = $key;
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $signals[$key]));
    }

    /**
     * @param array<string,mixed> $signals
     * @param array<string,mixed> $candidate
     * @param list<string> $missingSignals
     */
    private function evidenceNorm(array $signals, array $candidate, array &$missingSignals): float
    {
        if (array_key_exists('evidence_ready', $signals)) {
            return PgBoolean::isTrue($signals['evidence_ready']) ? 1.0 : 0.0;
        }
        if (array_key_exists('evidence_ready', $candidate)) {
            return PgBoolean::isTrue($candidate['evidence_ready']) ? 1.0 : 0.0;
        }

        $missingSignals[] = 'evidence_ready';
        return 0.0;
    }

    private function trendDelta(int $candidateId, string $forDate, int $daysBack, float $currentTotal): ?float
    {
        $targetDate = date('Y-m-d', strtotime($forDate . " -{$daysBack} days"));
        $prev = $this->db()->table('reach_topic_scores')
            ->where('topic_candidate_id', $candidateId)
            ->where('scored_for_date <=', $targetDate)
            ->orderBy('scored_for_date', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if (! $prev) {
            return null;
        }

        return round($currentTotal - (float) ($prev['total_score'] ?? 0), 2);
    }
}
