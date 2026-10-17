<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\Settings\ReachConfig;

/**
 * Runs a routine draft past the vetting model (Gemini) and turns its verdict
 * into the pipeline's decision of record:
 *
 *   approve           → publish the draft as submitted
 *   revise → approve  → publish the corrected text (re-vetted exactly once)
 *   revise → revise   → not approved: retired with the reasons, never published
 *   reject            → retired with the reasons, never published
 *
 * High risk. Gemini's approval is final, with one exception that keeps the
 * old "high risk never auto-publishes" ban meaningful: content the vetting
 * model ITSELF marks as risky — a compliance score below
 * automation.vetting.min_compliance_score (1–10 scale, default 5), or a reason
 * starting "HIGH_RISK:" — is held for a person instead of publishing. Reach's
 * own risk heuristics no longer second-guess an approval.
 *
 * VettingUnavailableException propagates: the caller leaves the draft pending.
 */
final class RoutineVetting
{
    public const RISK_PREFIX = 'HIGH_RISK';

    public function __construct(private readonly ContentVettingService $vetting)
    {
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $context
     * @return array{decision: string, approved: bool, final: array<string, mixed>, reasons: list<string>,
     *               scores: array<string, int>, model: string, run_id: ?int, run_ids: list<int>, high_risk: ?string}
     * @throws VettingUnavailableException
     */
    public function run(string $contentType, array $draft, array $context, string $contentRef): array
    {
        $context += [
            'scoring'   => 'Score each dimension from 1 (poor) to 10 (excellent).',
            'risk_flag' => 'If the article would still be risky to publish without a professional reviewer after your corrections '
                . '(for example specific tax or legal advice for an individual situation, or a claim whose error could cause a penalty), '
                . 'start one reason with "' . self::RISK_PREFIX . ':" and explain why.',
        ];

        $first   = $this->vetting->vet($contentType, $draft, $context, $contentRef);
        $runIds  = array_values(array_filter([$first->runId]));
        $reasons = $first->reasons;
        $final   = $first;

        if ($first->decision === VettingResult::REVISE) {
            $second  = $this->vetting->vet($contentType, $first->final, $context, $contentRef);
            $runIds  = array_values(array_filter([...$runIds, $second->runId]));
            $reasons = array_values(array_unique([...$first->reasons, ...$second->reasons]));
            $final   = $second;
        }

        $approved = $final->decision === VettingResult::APPROVE;
        $decision = $approved
            ? ($first->decision === VettingResult::REVISE ? 'revised_approved' : 'approved')
            : ($final->decision === VettingResult::REVISE ? 'revise_unresolved' : 'rejected');

        return [
            'decision'  => $decision,
            'approved'  => $approved,
            // On a re-vetted approval the final text is the revision the
            // second run approved (VettingResult::final echoes its input).
            'final'     => $approved ? $final->final : [],
            'reasons'   => $reasons,
            'scores'    => $final->scores,
            'model'     => $final->model,
            'run_id'    => $final->runId,
            'run_ids'   => $runIds,
            'high_risk' => $approved ? $this->highRisk($final) : null,
        ];
    }

    /** Why the vetting model's own verdict marks this as high risk, or null. */
    public function highRisk(VettingResult $result): ?string
    {
        foreach ($result->reasons as $reason) {
            if (stripos(ltrim($reason), self::RISK_PREFIX) === 0) {
                return 'vetting_flag';
            }
        }

        $min = (int) ReachConfig::get('automation.vetting.min_compliance_score', 5);
        if ($min > 0 && isset($result->scores['compliance']) && $result->scores['compliance'] < $min) {
            return 'low_compliance_score';
        }

        return null;
    }
}
