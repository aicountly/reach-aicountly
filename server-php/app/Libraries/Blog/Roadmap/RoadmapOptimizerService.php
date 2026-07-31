<?php

namespace App\Libraries\Blog\Roadmap;

use App\Libraries\Blog\BlogFeatureFlags;
use App\Libraries\Blog\BlogPortfolioService;
use App\Libraries\Blog\WorkBlockService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Orchestrates one daily roadmap optimisation run: score, decide, and enqueue brief work blocks.
 */
class RoadmapOptimizerService
{
    private ?BaseConnection $db = null;

    public function __construct(
        private ?BlogFeatureFlags $flags = null,
        private ?TopicScoringService $scoring = null,
        private ?RoadmapDecisionEngine $decisions = null,
        private ?RoadmapSignalProvider $signals = null,
        private ?StabilityController $stability = null,
        private ?BlogPortfolioService $portfolio = null,
    ) {
        $this->flags ??= new BlogFeatureFlags();
        $this->scoring ??= new TopicScoringService();
        $this->decisions ??= new RoadmapDecisionEngine();
        $this->signals ??= new RoadmapSignalProvider();
        $this->stability ??= new StabilityController();
        $this->portfolio ??= new BlogPortfolioService();
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function run(string $forDate, array $options = []): array
    {
        $dryRun = ! empty($options['dry_run']);
        $limit  = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;
        $started = microtime(true);

        $db = $this->db();
        if ($dryRun) {
            $db->transStart();
        }

        $runId = $this->createRun($forDate);
        $weights = $this->loadWeights();

        if (! $this->flags->isEnabled('roadmap_optimizer')) {
            $summary = [
                'run_id'         => $runId,
                'run_for_date'   => $forDate,
                'status'         => 'skipped',
                'skipped_reason' => 'optimizer_disabled',
                'dry_run'        => $dryRun,
            ];
            $this->completeRun($runId, 'skipped', $summary, $weights, $started, 'optimizer_disabled');
            if ($dryRun) {
                $db->transRollback();
            }
            return $summary;
        }

        $candidates = $this->loadCandidates($limit);
        $portfolioCounts = $this->signals->portfolioStreamCounts();
        $targetMix       = $this->signals->portfolioTargetMix();

        $scoredCount    = 0;
        $decisionCount  = 0;
        $workBlockCount = 0;
        $dailySelected  = 0;
        $dailyCap       = $this->stability->dailyCandidateCap();
        $weeklyCap      = $this->stability->weeklyPublicationCap();
        $weeklySince    = date('Y-m-d', strtotime($forDate . ' -7 days'));
        $weeklyUsed     = $this->stability->countWeeklyCreateDecisions($weeklySince);

        $ranked = [];

        foreach ($candidates as $candidate) {
            $candidateId = (int) $candidate['id'];
            $rawSignals  = $this->signals->gatherForCandidate($candidate, $forDate);
            $score       = $this->scoring->scoreCandidate($candidate, $rawSignals, $weights);

            $prevScore = $this->currentScoreTotal($candidateId);
            if ($this->stability->shouldSkipForMovement((float) $score['total_score'], $prevScore)) {
                $score['movement_skipped'] = true;
                if ($prevScore !== null) {
                    $score['total_score'] = $prevScore;
                }
            } else {
                if (! $dryRun) {
                    $this->scoring->persistScore($candidateId, $score, $forDate);
                }
                $scoredCount++;
            }

            $context  = $this->signals->buildDecisionContext($candidate, $rawSignals, $forDate);
            $context['min_score'] = (float) env('BLOG_ROADMAP_MIN_SCORE', 40);

            $stream = (string) ($candidate['portfolio_stream'] ?? '');
            if ($stream !== '' && $this->stability->portfolioOverConcentrated($stream, $portfolioCounts, $targetMix)) {
                if (empty($score['deductions']['portfolio_over_concentration'])) {
                    $score['deductions']['portfolio_over_concentration'] = (float) ($weights->deductions['portfolio_over_concentration'] ?? 15);
                    $score['deduction_total'] = round((float) ($score['deduction_total'] ?? 0) + (float) $score['deductions']['portfolio_over_concentration'], 2);
                    $score['total_score'] = max(0.0, round((float) $score['total_score'] - (float) $score['deductions']['portfolio_over_concentration'], 2));
                }
            }

            $decision = $this->decisions->decide($candidate, $score, $context);

            if ($dailySelected >= $dailyCap) {
                $decision = [
                    'decision'              => RoadmapDecisionEngine::HOLD,
                    'reason'                => 'Daily candidate cap reached.',
                    'requires_human_review' => false,
                ];
            }

            if (in_array($decision['decision'], [
                RoadmapDecisionEngine::CREATE_NEW,
                RoadmapDecisionEngine::REFRESH_EXISTING,
                RoadmapDecisionEngine::CREATE_PRODUCT_SUPPORT,
            ], true) && $weeklyUsed >= $weeklyCap) {
                $decision = [
                    'decision'              => RoadmapDecisionEngine::HOLD,
                    'reason'                => 'Weekly publication cap reached.',
                    'requires_human_review' => false,
                ];
            }

            $decisionId = 0;
            $workBlockId = null;
            if (! $dryRun) {
                $decisionId = $this->persistDecision($candidate, $score, $decision, $runId, $forDate, count($ranked) + 1);
                $decisionCount++;

                if ($decision['decision'] === RoadmapDecisionEngine::CREATE_NEW) {
                    $workBlockId = $this->createGenerateBriefWorkBlock($candidate, $decisionId);
                    if ($workBlockId !== null) {
                        $workBlockCount++;
                        $this->db()->table('reach_roadmap_decisions')->where('id', $decisionId)->update([
                            'work_block_id' => $workBlockId,
                            'updated_at'    => date('Y-m-d H:i:s'),
                        ]);
                    }
                    $weeklyUsed++;
                    $this->stability->applyCooldown($candidateId);
                }

                if (in_array($decision['decision'], [
                    RoadmapDecisionEngine::CREATE_NEW,
                    RoadmapDecisionEngine::REFRESH_EXISTING,
                    RoadmapDecisionEngine::CREATE_PRODUCT_SUPPORT,
                ], true)) {
                    $dailySelected++;
                    $streamKey = $this->normalizeStream($stream);
                    if ($streamKey !== '') {
                        $portfolioCounts[$streamKey] = ($portfolioCounts[$streamKey] ?? 0) + 1;
                    }
                }
            } else {
                if ($decision['decision'] === RoadmapDecisionEngine::CREATE_NEW) {
                    $dailySelected++;
                }
            }

            $ranked[] = [
                'candidate_id'   => $candidateId,
                'title'          => $candidate['title'] ?? '',
                'total_score'    => $score['total_score'],
                'decision'       => $decision['decision'],
                'reason'         => $decision['reason'],
                'work_block_id'  => $workBlockId,
                'dry_run'        => $dryRun,
            ];
        }

        usort($ranked, static fn ($a, $b) => ($b['total_score'] <=> $a['total_score']));

        $summary = [
            'run_id'               => $runId,
            'run_for_date'         => $forDate,
            'status'               => 'completed',
            'candidates_considered'=> count($candidates),
            'candidates_scored'    => $scoredCount,
            'decisions_created'    => $decisionCount,
            'work_blocks_created'  => $workBlockCount,
            'dry_run'              => $dryRun,
            'ranked'               => $ranked,
        ];

        $this->completeRun($runId, 'completed', $summary, $weights, $started);

        if ($dryRun) {
            $db->transRollback();
        }

        return $summary;
    }

    private function createRun(string $forDate): int
    {
        $this->db()->table('reach_optimizer_runs')->insert([
            'run_uuid'     => bin2hex(random_bytes(16)),
            'run_for_date' => $forDate,
            'status'       => 'running',
            'started_at'   => date('Y-m-d H:i:s'),
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db()->insertID();
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function completeRun(
        int $runId,
        string $status,
        array $summary,
        ScoringWeights $weights,
        float $startedAt,
        ?string $skippedReason = null,
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->db()->table('reach_optimizer_runs')->where('id', $runId)->update([
            'status'                => $status,
            'candidates_considered' => (int) ($summary['candidates_considered'] ?? 0),
            'candidates_scored'     => (int) ($summary['candidates_scored'] ?? 0),
            'decisions_created'     => (int) ($summary['decisions_created'] ?? 0),
            'work_blocks_created'   => (int) ($summary['work_blocks_created'] ?? 0),
            'skipped_reason'        => $skippedReason,
            'summary_json'          => json_encode($summary, JSON_UNESCAPED_SLASHES),
            'weights_json'          => json_encode($weights->toArray(), JSON_UNESCAPED_SLASHES),
            'completed_at'          => date('Y-m-d H:i:s'),
            'duration_ms'           => $durationMs,
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }

    private function loadWeights(): ScoringWeights
    {
        if (! $this->db()->tableExists('reach_roadmap_scoring_weights')) {
            return ScoringWeights::defaults();
        }

        $row = $this->db()->table('reach_roadmap_scoring_weights')
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        return $row ? ScoringWeights::fromArray($row) : ScoringWeights::defaults();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadCandidates(int $limit): array
    {
        $builder = $this->db()->table('reach_topic_candidates')
            ->whereIn('status', ['candidate', 'scored'])
            ->where('is_locked', false)
            ->groupStart()
                ->where('cooldown_until IS NULL', null, false)
                ->orWhere('cooldown_until <=', date('Y-m-d H:i:s'))
            ->groupEnd()
            ->orderBy('id', 'ASC');

        if ($limit > 0) {
            $builder->limit($limit);
        }

        return $builder->get()->getResultArray();
    }

    private function currentScoreTotal(int $candidateId): ?float
    {
        if (! $this->db()->tableExists('reach_topic_scores')) {
            return null;
        }

        $row = $this->db()->table('reach_topic_scores')
            ->select('total_score')
            ->where('topic_candidate_id', $candidateId)
            ->where('is_current', true)
            ->get()
            ->getRowArray();

        return $row ? (float) $row['total_score'] : null;
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $score
     * @param array<string,mixed> $decision
     */
    private function persistDecision(
        array $candidate,
        array $score,
        array $decision,
        int $runId,
        string $forDate,
        int $rank,
    ): int {
        $this->db()->table('reach_roadmap_decisions')->insert([
            'topic_candidate_id'    => (int) ($candidate['id'] ?? 0),
            'content_item_id'       => $candidate['content_item_id'] ?? null,
            'decision'              => $decision['decision'],
            'decision_reason'       => $decision['reason'],
            'score_at_decision'     => $score['total_score'],
            'rank_at_decision'      => $rank,
            'portfolio_stream'      => $candidate['portfolio_stream'] ?? null,
            'requires_human_review' => ! empty($decision['requires_human_review']),
            'optimizer_run_id'      => $runId,
            'decided_for_date'      => $forDate,
            'actor_type'            => 'system',
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db()->insertID();
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function createGenerateBriefWorkBlock(array $candidate, int $decisionId): ?int
    {
        $key = 'roadmap-' . $decisionId;
        $existing = $this->db()->table('reach_work_blocks')
            ->where('idempotency_key', $key)
            ->get()
            ->getRowArray();
        if ($existing) {
            return (int) $existing['id'];
        }

        $this->db()->table('reach_work_blocks')->insert([
            'block_type'         => WorkBlockService::TYPE_GENERATE_BRIEF,
            'scope'              => 'roadmap',
            'content_item_id'    => $candidate['content_item_id'] ?? null,
            'input_json'         => json_encode([
                'topic_candidate_id' => (int) ($candidate['id'] ?? 0),
                'title'              => $candidate['title'] ?? '',
                'portfolio_stream'   => $candidate['portfolio_stream'] ?? null,
                'decision_id'        => $decisionId,
            ], JSON_UNESCAPED_SLASHES),
            // Eligible immediately: the optimizer is the human-approved roadmap decision
            // (CREATE_NEW) that authorizes this brief. Leaving it 'pending' meant the
            // dispatcher's enqueueEligibleBatch() would never pick it up.
            'eligibility_status' => 'eligible',
            'idempotency_key'    => $key,
            'priority'           => (int) ($candidate['publication_priority'] ?? 0),
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db()->insertID();
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

    private function db(): BaseConnection
    {
        return $this->db ??= Database::connect();
    }
}
