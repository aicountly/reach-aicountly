<?php

declare(strict_types=1);

namespace App\Libraries\Community;

use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityRiskTier;
use App\Libraries\AuditLogger;
use App\Libraries\Blog\BlogAutoPublishService;
use Throwable;

/**
 * Drives a generated official answer from `draft_generated` to a terminal
 * outcome without a human having to click through five screens.
 *
 * Governance is unchanged, and deliberately so:
 *
 *   • Tier 0/1 (product usage, general education) may auto-approve and publish,
 *     and only when COMMUNITY_AUTO_PUBLISH_ENABLED is explicitly on.
 *   • Tier 2/3 (regulatory, individualised advice) are *never* auto-approved —
 *     CommunityRiskTier::requiresProfessionalApproval() and the publish-time
 *     risk gate both refuse them. They are routed into the human review queue
 *     so a superadmin sees them, which is what was missing: they previously
 *     dead-ended in validation_failed and were invisible to the queue.
 *   • Tier 4 is archived-eligible, never publishable.
 *
 * So turning the flag on does not make compliance answers self-publish; it
 * makes low-risk product answers self-publish and everything else *queue*.
 */
class CommunityAutoPublishService
{
    public function __construct(
        private readonly OfficialAnswerRepository        $answerRepo = new OfficialAnswerRepository(),
        private readonly OfficialAnswerLifecycleService  $lifecycle  = new OfficialAnswerLifecycleService(),
        private readonly OfficialAnswerPublishingService $publishing = new OfficialAnswerPublishingService(),
    ) {}

    public static function isEnabled(): bool
    {
        return filter_var(env('COMMUNITY_AUTO_PUBLISH_ENABLED', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Advance one answer as far as its risk tier and the flags allow.
     *
     * @return array{answer_id:int,outcome:string,status?:string,risk_tier?:int,detail?:string}
     */
    public function advance(int $answerId): array
    {
        $answer = $this->answerRepo->findById($answerId);
        if ($answer === null) {
            return ['answer_id' => $answerId, 'outcome' => 'answer_not_found'];
        }

        $uuid   = (string) $answer['uuid'];
        $status = CommunityAnswerStatus::from((string) $answer['status']);
        $tier   = $this->lifecycle->riskTierOf($answer);

        if ($tier === CommunityRiskTier::Prohibited) {
            return $this->result($answerId, 'blocked_prohibited_tier', $status, $tier);
        }

        if ($status !== CommunityAnswerStatus::DraftGenerated) {
            return $this->result($answerId, 'not_a_generated_draft', $status, $tier);
        }

        $versionNumber = $this->lifecycle->currentVersionNumber($answerId);
        if ($versionNumber <= 0) {
            return $this->result($answerId, 'no_version_to_advance', $status, $tier);
        }

        // Quality gates first — they are the same ones the UI runs.
        $validation = $this->lifecycle->validate($uuid, $versionNumber);
        if (! ($validation['passed'] ?? false)) {
            return $this->result($answerId, 'validation_failed', $status, $tier, $this->firstFinding($validation));
        }

        $moderation = $this->lifecycle->moderate($uuid, $versionNumber);
        if (($moderation['blocked'] ?? false) === true) {
            return $this->result($answerId, 'moderation_blocked', $status, $tier, $this->firstFinding($moderation));
        }

        // Everything that survives the gates belongs in a review queue. For
        // tier 2/3 that *is* the terminal automated outcome.
        $review = $this->lifecycle->submitForReview($uuid);
        $queued = (string) ($review['status'] ?? '');

        if ($tier->requiresProfessionalApproval() || ! $tier->isAutoPublishable()) {
            return $this->result(
                $answerId,
                'queued_for_human_review',
                CommunityAnswerStatus::tryFrom($queued) ?? $status,
                $tier,
                'Risk tier ' . $tier->value . ' (' . $tier->label() . ') requires a human approval.',
            );
        }

        if (! self::isEnabled()) {
            return $this->result(
                $answerId,
                'queued_for_human_review',
                CommunityAnswerStatus::tryFrom($queued) ?? $status,
                $tier,
                'COMMUNITY_AUTO_PUBLISH_ENABLED is off.',
            );
        }

        $actorId = (new BlogAutoPublishService())->ensureSystemBotUserId();

        try {
            $this->lifecycle->approve(
                $uuid,
                $actorId,
                $versionNumber,
                'standard',
                'Auto-approved: risk tier ' . $tier->value . ' with COMMUNITY_AUTO_PUBLISH_ENABLED',
            );
        } catch (Throwable $e) {
            return $this->result($answerId, 'approval_failed', $status, $tier, mb_substr($e->getMessage(), 0, 200));
        }

        try {
            $result = $this->publishing->publish($answerId, $actorId);
        } catch (Throwable $e) {
            return $this->result($answerId, 'publish_failed', $status, $tier, mb_substr($e->getMessage(), 0, 200));
        }

        AuditLogger::record('community.auto_published', [
            'answer_id'     => $answerId,
            'risk_tier'     => $tier->value,
            'canonical_url' => $result['canonical_url'] ?? null,
        ], $actorId);

        return [
            'answer_id'     => $answerId,
            'outcome'       => 'published',
            'status'        => CommunityAnswerStatus::Published->value,
            'risk_tier'     => $tier->value,
            'canonical_url' => $result['canonical_url'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $gate
     */
    private function firstFinding(array $gate): string
    {
        $findings = $gate['findings'] ?? [];
        if (! is_array($findings) || $findings === []) {
            return '';
        }

        $first = reset($findings);

        return is_array($first)
            ? mb_substr((string) ($first['detail'] ?? $first['type'] ?? ''), 0, 200)
            : mb_substr((string) $first, 0, 200);
    }

    /**
     * @return array{answer_id:int,outcome:string,status:string,risk_tier:int,detail?:string}
     */
    private function result(
        int $answerId,
        string $outcome,
        CommunityAnswerStatus $status,
        CommunityRiskTier $tier,
        string $detail = '',
    ): array {
        $out = [
            'answer_id' => $answerId,
            'outcome'   => $outcome,
            'status'    => $status->value,
            'risk_tier' => $tier->value,
        ];

        if ($detail !== '') {
            $out['detail'] = $detail;
        }

        return $out;
    }
}
