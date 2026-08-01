<?php

namespace App\Libraries\Blog\Roadmap;

use App\Libraries\Support\PgBoolean;

/**
 * Deterministic roadmap decision rules for scored topic candidates.
 *
 * Rule order (first match wins):
 * 1. locked → HOLD
 * 2. human_pinned → CREATE_NEW (bypasses cooldown and score floor)
 * 3. active cooldown → HOLD
 * 4. cannibalisation deduction → MERGE_COMPETING
 * 5. near_duplicate deduction → REJECT
 * 6. weak_evidence or missing evidence with HIGH risk → REQUIRE_HUMAN_REVIEW
 * 7. existing content stale → REFRESH_EXISTING
 * 8. existing content weak CTR + strong impressions → IMPROVE_TITLE_META
 * 9. existing content with low internal link value → IMPROVE_INTERNAL_LINKS
 * 10. portfolio_over_concentration deduction → HOLD
 * 11. total score below minimum → HOLD
 * 12. product-stream + non-production product → CREATE_PRODUCT_SUPPORT if flagged else HOLD
 * 13. default → CREATE_NEW
 */
class RoadmapDecisionEngine
{
    public const CREATE_NEW             = 'CREATE_NEW';
    public const REFRESH_EXISTING       = 'REFRESH_EXISTING';
    public const EXPAND_EXISTING        = 'EXPAND_EXISTING';
    public const MERGE_COMPETING        = 'MERGE_COMPETING';
    public const IMPROVE_TITLE_META     = 'IMPROVE_TITLE_META';
    public const IMPROVE_INTERNAL_LINKS = 'IMPROVE_INTERNAL_LINKS';
    public const CREATE_PRODUCT_SUPPORT = 'CREATE_PRODUCT_SUPPORT';
    public const HOLD                   = 'HOLD';
    public const REJECT                 = 'REJECT';
    public const REQUIRE_HUMAN_REVIEW   = 'REQUIRE_HUMAN_REVIEW';

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $score
     * @param array<string,mixed> $context
     * @return array{decision:string,reason:string,requires_human_review:bool}
     */
    public function decide(array $candidate, array $score, array $context = []): array
    {
        $deductions = $score['deductions'] ?? [];
        $total      = (float) ($score['total_score'] ?? 0);
        $minScore   = (float) ($context['min_score'] ?? env('BLOG_ROADMAP_MIN_SCORE', 40));

        if (PgBoolean::isTrue($candidate['is_locked'] ?? false)) {
            return $this->result(self::HOLD, 'Candidate is locked.');
        }

        // Pinned topics are an explicit operator override — evaluate before cooldown.
        if (PgBoolean::isTrue($candidate['is_human_pinned'] ?? false)) {
            return $this->result(self::CREATE_NEW, 'Human-pinned candidate.');
        }

        if ($this->cooldownActive($candidate)) {
            return $this->result(self::HOLD, 'Candidate is in cooldown.');
        }

        if (! empty($deductions['cannibalisation'])) {
            return $this->result(self::MERGE_COMPETING, 'Cannibalisation risk detected.');
        }

        if (! empty($deductions['near_duplicate'])) {
            return $this->result(self::REJECT, 'Near-duplicate topic detected.');
        }

        $risk = strtoupper((string) ($context['risk_level'] ?? $candidate['risk_level'] ?? 'LOW'));
        $evidenceReady = PgBoolean::isTrue($candidate['evidence_ready'] ?? false)
            || PgBoolean::isTrue($context['evidence_ready'] ?? false)
            || PgBoolean::isTrue($score['inputs']['signals']['evidence_ready'] ?? false);

        if ((! empty($deductions['weak_evidence']) || ! $evidenceReady) && $risk === 'HIGH') {
            return $this->result(self::REQUIRE_HUMAN_REVIEW, 'High-risk topic lacks sufficient evidence.', true);
        }

        $contentItemId = (int) ($candidate['content_item_id'] ?? 0);
        if ($contentItemId > 0 && ! empty($context['content_stale'])) {
            return $this->result(self::REFRESH_EXISTING, 'Linked content is stale.');
        }

        if ($contentItemId > 0 && $this->weakCtrStrongImpressions($context, $score)) {
            return $this->result(self::IMPROVE_TITLE_META, 'Strong impressions with weak CTR.');
        }

        $internalFactor = (float) ($score['factors']['internal_link_value'] ?? 0);
        $internalWeight = (int) (($score['weights']['internal_link_value'] ?? 5));
        $internalNorm   = $internalWeight > 0 ? $internalFactor / $internalWeight : 0.0;
        if ($contentItemId > 0 && $internalNorm < 0.3) {
            return $this->result(self::IMPROVE_INTERNAL_LINKS, 'Existing content has low internal link value.');
        }

        if (! empty($deductions['portfolio_over_concentration'])) {
            return $this->result(self::HOLD, 'Portfolio stream is over-concentrated.');
        }

        if ($total < $minScore) {
            return $this->result(self::HOLD, "Score {$total} is below minimum {$minScore}.");
        }

        $stream = (string) ($candidate['portfolio_stream'] ?? '');
        if ($stream === 'product' && empty($context['product_production_ready'])) {
            if (! empty($context['create_product_support_flagged'])) {
                return $this->result(self::CREATE_PRODUCT_SUPPORT, 'Product support content required before product article.');
            }

            return $this->result(self::HOLD, 'Primary product is not production-ready.');
        }

        return $this->result(self::CREATE_NEW, 'Candidate meets scoring threshold.');
    }

    /**
     * @return array{decision:string,reason:string,requires_human_review:bool}
     */
    private function result(string $decision, string $reason, bool $requiresHumanReview = false): array
    {
        if ($decision === self::REQUIRE_HUMAN_REVIEW) {
            $requiresHumanReview = true;
        }

        return [
            'decision'              => $decision,
            'reason'                => $reason,
            'requires_human_review' => $requiresHumanReview,
        ];
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function cooldownActive(array $candidate): bool
    {
        $until = $candidate['cooldown_until'] ?? null;
        if ($until === null || $until === '') {
            return false;
        }

        return strtotime((string) $until) > time();
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $score
     */
    private function weakCtrStrongImpressions(array $context, array $score): bool
    {
        $signals = $score['inputs']['signals'] ?? $context['signals'] ?? [];
        $ctr         = isset($signals['ctr']) ? (float) $signals['ctr'] : null;
        $impressions = isset($signals['impressions']) ? (float) $signals['impressions'] : null;

        if ($ctr === null || $impressions === null) {
            return false;
        }

        if ($ctr > 1.0) {
            $ctr = $ctr / 100.0;
        }

        return $ctr < 0.02 && $impressions >= 1000;
    }
}
