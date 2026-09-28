<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Models\CommunityAgentRunModel;
use App\Models\CommunityOfficialIdentityModel;

/**
 * The single role-routed operational agent runtime (Decision 2A).
 *
 * There are five operational roles, not five separate agent modules — every
 * official identity's `operational_role` column determines which actions it
 * may take, and this class is the one place that decides whether an action
 * is (a) permitted for that role, (b) inside the automation window, (c)
 * under its daily cap, and only then (d) executed by delegating to the
 * relevant existing domain service. Every attempt — permitted or blocked —
 * is written to reach_community_agent_runs, which is also where the caps and
 * self-reply checks read from.
 *
 * Automated votes/likes/follows/view-inflation are not "capped" here because
 * they are not reachable at all: no action in ROLE_ACTIONS exposes them, and
 * CommunityStewardService (the role most tempted to "boost" content) has no
 * such method to call in the first place.
 */
class CommunityOperationalAgentService
{
    /** @var array<string, list<string>> */
    private const ROLE_ACTIONS = [
        'question_curator'        => ['curate_question'],
        'expert_answer_assistant' => ['draft_answer'],
        'community_steward'       => ['categorize_question', 'link_related_questions', 'flag_moderation_hint'],
        'thread_facilitator'      => ['post_comment'],
        'review_objection_desk'   => ['flag_objection', 'request_revision', 'escalate_risk'],
    ];

    /** Seed defaults per plan: 4 questions curated/day, 4 answers drafted/day, <=5 comments/day. */
    private const DAILY_CAPS = [
        'curate_question' => 4,
        'draft_answer'    => 4,
        'post_comment'    => 5,
    ];

    /** Actions that create new public-facing content are window-gated; pure review/analysis actions are not. */
    private const WINDOW_GATED_ACTIONS = ['curate_question', 'draft_answer', 'post_comment'];

    /** The expert desk that answers each question category; anything else goes to DEFAULT_DESK. */
    private const CATEGORY_DESKS = [
        'accounting'     => 'aicountly-accounting-guide',
        'gst'            => 'aicountly-gst-guide',
        'income-tax'     => 'aicountly-income-tax-desk',
        'tds-tcs'        => 'aicountly-income-tax-desk',
        'payroll-hr'     => 'aicountly-payroll-desk',
        'product-guides' => 'aicountly-smart-books-guide',
        'books'          => 'aicountly-smart-books-guide',
    ];
    private const DEFAULT_DESK = 'aicountly-compliance-desk';

    /**
     * Automatic draft attempts a question gets before the bot stops retrying
     * it. After that a human can still regenerate from the answer editor.
     */
    public const MAX_AUTOMATIC_DRAFT_ATTEMPTS = 6;

    public function __construct(
        private readonly CommunityOfficialIdentityModel      $identityModel = new CommunityOfficialIdentityModel(),
        private readonly CommunityAgentRunModel               $runModel      = new CommunityAgentRunModel(),
        private readonly CommunityQuestionCurationService     $curation      = new CommunityQuestionCurationService(),
        private readonly OfficialAnswerLifecycleService       $answers       = new OfficialAnswerLifecycleService(),
        private readonly CommunityStewardService              $steward       = new CommunityStewardService(),
        private readonly CommunityThreadFacilitationService   $facilitation  = new CommunityThreadFacilitationService(),
        private readonly CommunityReviewObjectionService      $review        = new CommunityReviewObjectionService(),
    ) {}

    /** Which actions a role may perform. Exposed so callers/tests can introspect without duplicating the map. */
    public static function actionsForRole(string $role): array
    {
        return self::ROLE_ACTIONS[$role] ?? [];
    }

    public static function isWindowGated(string $action): bool
    {
        return in_array($action, self::WINDOW_GATED_ACTIONS, true);
    }

    public static function dailyCapFor(string $action): ?int
    {
        return self::DAILY_CAPS[$action] ?? null;
    }

    /**
     * The desk that answers a question in this category. The agents run and
     * a person creating an answer both route through here, so a question
     * lands on the same desk whoever starts its draft.
     */
    public static function deskForCategory(?string $category): string
    {
        return self::CATEGORY_DESKS[strtolower($category ?? '')] ?? self::DEFAULT_DESK;
    }

    /**
     * Every desk deskForCategory() can return.
     *
     * @return list<string>
     */
    public static function answerDesks(): array
    {
        return array_values(array_unique([...array_values(self::CATEGORY_DESKS), self::DEFAULT_DESK]));
    }

    /**
     * How long to wait after a question's Nth failed automatic draft.
     *
     * Doubling from 30 minutes (one agents-run tick), capped at 12 hours: the
     * five waits before the last attempt total 15.5 hours, so a provider
     * outage or a missing route gets most of a day to recover rather than
     * one failed tick stranding the question for good — without hammering a
     * provider that is already failing.
     */
    public static function draftRetryBackoffSeconds(int $failedAttempts): int
    {
        return (int) min(43200, 1800 * (2 ** max(0, $failedAttempts - 1)));
    }

    /** Is a question with this many failed automatic drafts due another attempt? */
    public static function isDraftRetryDue(int $failedAttempts, int $secondsSinceLastFailure): bool
    {
        if ($failedAttempts <= 0) {
            return true;
        }

        return $failedAttempts < self::MAX_AUTOMATIC_DRAFT_ATTEMPTS
            && $secondsSinceLastFailure >= self::draftRetryBackoffSeconds($failedAttempts);
    }

    /**
     * @param string $identitySlug The official identity performing the action.
     * @param string $action       One of the ROLE_ACTIONS values.
     * @param array<string,mixed> $context Action-specific payload.
     * @param bool $forceWindow Test/ops override to bypass the automation window (never exposed to bot-triggered dispatch).
     */
    public function dispatch(string $identitySlug, string $action, array $context = [], ?int $actorId = null, bool $forceWindow = false): array
    {
        $identity = $this->identityModel->findBySlug($identitySlug);
        if ($identity === null || ! ($identity['is_active'] ?? false)) {
            throw new \InvalidArgumentException("Unknown or inactive official identity: {$identitySlug}");
        }

        $role = (string) $identity['operational_role'];
        $identityId = (int) $identity['id'];

        if (! in_array($action, self::actionsForRole($role), true)) {
            throw new \RuntimeException("Action '{$action}' is not permitted for operational role '{$role}'.");
        }

        if (! $forceWindow && self::isWindowGated($action) && ! CommunityAutomationWindow::isOpenNow()) {
            return $this->blocked($identity, $role, $action, 'outside_automation_window', $actorId);
        }

        $cap = self::dailyCapFor($action);
        if ($cap !== null && $this->runModel->countSuccessfulToday($identityId, $action) >= $cap) {
            return $this->blocked($identity, $role, $action, 'daily_cap_reached', $actorId);
        }

        try {
            $result = match ($action) {
                'curate_question'        => $this->curation->curate($identity, $context, $actorId),
                'draft_answer'           => $this->draftAnswer($identity, $context, $actorId),
                'categorize_question'    => $this->steward->categorize($identity, $context, $actorId),
                'link_related_questions' => $this->steward->linkRelated($identity, $context, $actorId),
                'flag_moderation_hint'   => $this->steward->flagModerationHint($identity, $context, $actorId),
                'post_comment'           => $this->facilitation->postComment($identity, $context, $actorId),
                'flag_objection'         => $this->review->flagObjection($identity, $context, $actorId),
                'request_revision'       => $this->review->requestRevision($identity, $context, $actorId),
                'escalate_risk'          => $this->review->escalateRisk($identity, $context, $actorId),
                default                  => throw new \RuntimeException("Unhandled action '{$action}'."),
            };
        } catch (\Throwable $e) {
            $this->recordRun($identity, $role, $action, 'failed', $context, $actorId, substr($e->getMessage(), 0, 120));
            throw $e;
        }

        $this->recordRun($identity, $role, $action, 'success', array_merge($context, $result), $actorId);

        return $result;
    }

    private function draftAnswer(array $identity, array $context, ?int $actorId): array
    {
        $questionUuid = (string) ($context['question_uuid'] ?? '');
        if ($questionUuid === '') {
            throw new \InvalidArgumentException('draft_answer requires question_uuid.');
        }

        // A first draft whose generation failed is retried in place. The owning
        // desk retries it, so the answer is never re-attributed to another
        // identity behind its back.
        $answer = $this->answers->findRetryableDraft($questionUuid);
        if ($answer !== null && (int) $answer['identity_id'] !== (int) $identity['id']) {
            throw new \RuntimeException(
                "Answer {$answer['uuid']} belongs to another official identity; only its owner retries its generation."
            );
        }
        $retried = $answer !== null;
        $answer ??= $this->answers->createDraft($questionUuid, $identity['slug'], $actorId);

        $generation = $this->answers->requestGeneration(
            (string) $answer['uuid'],
            (string) ($context['answer_type'] ?? 'detailed'),
            $actorId
        );

        return [
            'answer_uuid'          => $answer['uuid'],
            'version_number'       => $generation['version']['version_number'] ?? null,
            'risk_classification'  => $generation['risk_classification'] ?? null,
            'retried'              => $retried,
        ];
    }

    private function blocked(array $identity, string $role, string $action, string $reason, ?int $actorId): array
    {
        $this->recordRun($identity, $role, $action, 'blocked', [], $actorId, $reason);

        AuditLogger::record(AuditLogger::COMMUNITY_AGENT_RUN_BLOCKED, [
            'identity_slug' => $identity['slug'],
            'role'          => $role,
            'action'        => $action,
            'reason'        => $reason,
        ], $actorId);

        return ['ok' => false, 'blocked' => true, 'reason' => $reason];
    }

    private function recordRun(array $identity, string $role, string $action, string $outcome, array $metadata, ?int $actorId, ?string $blockReason = null): void
    {
        [$targetType, $targetId] = match (true) {
            isset($metadata['comment_external_id']) => ['comment', null],
            isset($metadata['answer_uuid'])         => ['answer', null],
            isset($metadata['question_id'])         => ['question', (int) $metadata['question_id']],
            isset($metadata['question_uuid'])       => ['question', null],
            default                                  => [null, null],
        };

        $targetExternalRef = $metadata['comment_external_id']
            ?? $metadata['answer_uuid']
            ?? $metadata['question_uuid']
            ?? null;

        $this->runModel->insert([
            'identity_id'         => (int) $identity['id'],
            'operational_role'    => $role,
            'action'              => $action,
            'target_type'         => $targetType,
            'target_id'           => $targetId,
            'target_external_ref' => is_string($targetExternalRef) ? $targetExternalRef : null,
            'prompt_version'      => $metadata['prompt_version'] ?? null,
            'provider'            => $metadata['provider'] ?? null,
            'model_route'         => $metadata['model_route'] ?? null,
            'outcome'             => $outcome,
            'block_reason'        => $blockReason,
            'metadata'            => json_encode($metadata),
            'actor_id'            => $actorId,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
    }
}
