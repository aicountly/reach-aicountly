<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Commands\CommunityCategoryCheck;
use App\Enums\CommunityAnswerStatus;
use App\Enums\CommunityQuestionStatus;
use App\Enums\CommunityRiskTier;
use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\AuditLogger;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\Community\CommunityOperationalAgentService;
use App\Libraries\Community\CommunityPublicationVerificationService;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\CommunityQuestionRepository;
use App\Libraries\Community\OfficialAnswerLifecycleService;
use App\Libraries\Community\OfficialAnswerRepository;
use App\Libraries\Community\OfficialAnswerVersionService;
use App\Libraries\HtmlSanitizer;
use App\Libraries\SaasProductTaxonomy;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Support\PgBoolean;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Community Q&A written by the reach-community-qa Claude Routine.
 *
 * Reach does not write community answers itself. The routine submits either a
 * frequently-asked question with its answer (submitFaq) or an answer to an
 * open member question (submitMemberAnswer). Each submission follows one path:
 *
 *   validate → idempotency (external_key) → quota → question + desk answer
 *   → structural gates (validation, moderation) → vetting (community rubric)
 *   → on revise: apply the corrections and re-vet once → on approve: approve
 *   under SystemApprover (tier 2/3 as compliance_review, naming the vetting
 *   run) and publish → verify it is live.
 *
 * Reject (or a second revise) withdraws the submission with the reasons;
 * tier 4 is blocked and never vetted; VettingUnavailableException leaves it
 * pending for community:schedule to retry. Nothing unvetted is published.
 *
 * Attribution: a routine-written question is the desk's own FAQ post
 * (source_type official_question, author_type official_bot on the public
 * site, ai_assisted). Nothing is attributed to a member of the public, and
 * the answer is never flagged human_reviewed — the approval is the system
 * account's, recorded against the vetting run it rests on.
 */
class CommunityQaAutomationService
{
    public const MAX_PER_RUN       = 3;
    public const MAX_ATTEMPTS      = 24;
    public const MAX_REVISIONS     = 1;
    private const LEASE_SECONDS    = 900;
    private const IST              = 'Asia/Kolkata';

    /**
     * Topic mix the routine should aim for: weighted to the apps launching
     * now, plus the everyday Indian tax and compliance questions small
     * businesses and accountants ask. `share` is a fraction of the day's
     * questions; recent counts are added by plan().
     */
    public const MIX_TARGETS = [
        ['key' => 'smart-books',   'topic' => 'Smart Books accounting (vouchers, ledgers, bank reconciliation, reports)', 'category_slug' => 'saas-product-help', 'product_slug' => 'smart_books', 'share' => 0.25],
        ['key' => 'gst-billing',   'topic' => 'GST billing and invoicing (tax invoices, e-invoicing, credit notes)',        'category_slug' => 'gst',               'product_slug' => 'billing',     'share' => 0.20],
        ['key' => 'inventory',     'topic' => 'Inventory (items, stock, godowns, valuation)',                               'category_slug' => 'saas-product-help', 'product_slug' => 'inventory',   'share' => 0.15],
        ['key' => 'productivity',  'topic' => 'Notes and Drive for small-business productivity',                            'category_slug' => 'saas-product-help', 'product_slug' => 'notes',       'share' => 0.10],
        ['key' => 'gst',           'topic' => 'GST compliance (registration, returns, ITC, due dates)',                     'category_slug' => 'gst',               'product_slug' => null,          'share' => 0.10],
        ['key' => 'income-tax',    'topic' => 'Income tax, TDS and TCS for small businesses',                               'category_slug' => 'income-tax',        'product_slug' => null,          'share' => 0.10],
        ['key' => 'accounting',    'topic' => 'Bookkeeping, accounting and audit basics',                                   'category_slug' => 'audit-accounting',  'product_slug' => null,          'share' => 0.05],
        ['key' => 'payroll',       'topic' => 'Payroll, PF and ESI',                                                        'category_slug' => 'payroll',           'product_slug' => null,          'share' => 0.05],
    ];

    /** Answer statuses a routine answer may take over: drafts that never reached review. */
    private const TAKE_OVER_STATUSES = [
        CommunityAnswerStatus::DraftRequested,
        CommunityAnswerStatus::ValidationFailed,
        CommunityAnswerStatus::DraftGenerated,
        CommunityAnswerStatus::ChangesRequested,
        CommunityAnswerStatus::ModerationRequired,
    ];

    private static ?ContentVettingService $vettingOverride = null;

    private BaseConnection $db;
    private ContentVettingService $vetting;

    public function __construct(
        ?ContentVettingService $vetting = null,
        private readonly OfficialAnswerLifecycleService $lifecycle = new OfficialAnswerLifecycleService(),
        private readonly OfficialAnswerRepository $answers = new OfficialAnswerRepository(),
        private readonly CommunityQuestionRepository $questions = new CommunityQuestionRepository(),
        private readonly CommunityQuestionIntakeService $intake = new CommunityQuestionIntakeService(),
        private readonly OfficialAnswerVersionService $versions = new OfficialAnswerVersionService(),
        ?BaseConnection $db = null,
    ) {
        $this->vetting = $vetting ?? self::$vettingOverride ?? new ContentVettingService();
        $this->db      = $db ?? Database::connect();
    }

    /** Tests: the vetting service HTTP-level callers get. */
    public static function useVetting(?ContentVettingService $vetting): void
    {
        self::$vettingOverride = $vetting;
    }

    /** The vetting service other automation (the inbox triage) should use. */
    public static function vettingService(): ContentVettingService
    {
        return self::$vettingOverride ?? new ContentVettingService();
    }

    // -------------------------------------------------------------------------
    // Plan
    // -------------------------------------------------------------------------

    /** @return array{enabled: bool, daily_limit: int, used_today: int, remaining_today: int, max_this_run: int} */
    public function budget(): array
    {
        $enabled = (bool) ReachConfig::get('automation.community.enabled', true);
        $limit   = max(0, (int) ReachConfig::get('automation.community.daily_limit', 10));
        $used    = $this->usedToday();
        $remain  = $enabled ? max(0, $limit - $used) : 0;

        return [
            'enabled'         => $enabled,
            'daily_limit'     => $limit,
            'used_today'      => $used,
            'remaining_today' => $remain,
            'max_this_run'    => min(self::MAX_PER_RUN, $remain),
        ];
    }

    /** What the routine should do this run. */
    public function plan(): array
    {
        $enabled = (bool) ReachConfig::get('automation.community.enabled', true);
        $limit   = max(0, (int) ReachConfig::get('automation.community.daily_limit', 10));
        $used    = $this->usedToday();
        $remain  = $enabled ? max(0, $limit - $used) : 0;

        return [
            'enabled'                => $enabled,
            'day'                    => $this->today()->format('Y-m-d'),
            'daily_limit'            => $limit,
            'used_today'             => $used,
            'remaining_today'        => $remain,
            'max_this_run'           => min(self::MAX_PER_RUN, $remain),
            'open_questions'         => $this->openQuestions(20),
            'recent_question_titles' => $this->recentTitles(200),
            'mix_targets'            => $this->mixWithRecentCounts(),
            'categories'             => CommunityCategoryCheck::PUBLIC_SLUGS,
            'products'               => SaasProductTaxonomy::products(),
            'allowed_html_tags'      => config(\Config\ContentSanitization::class)->allowedTags,
            'pending_retry'          => $this->countByStatus('pending'),
        ];
    }

    // -------------------------------------------------------------------------
    // Submissions
    // -------------------------------------------------------------------------

    /**
     * A routine-written frequently-asked question with its answer.
     *
     * @throws InvalidArgumentException  invalid payload (422)
     * @throws CommunityQaRefusal        disabled (503), quota (429), duplicate (409)
     */
    public function submitFaq(array $payload): array
    {
        $in = CommunityQaPayload::faq($payload);

        $this->assertEnabled();
        if (($existing = $this->findByKey($in['external_key'])) !== null) {
            return ['status' => 'already_submitted'] + $this->view($existing);
        }
        $this->assertQuota();
        $this->assertNotDuplicateTitle($in['question_title']);

        $desk = CommunityOperationalAgentService::deskFor($in['category_slug'], $in['product_slug']);

        $this->db->transBegin();
        try {
            $question = $this->intake->intake([
                'source_type'          => 'official_question',
                'title'                => $in['question_title'],
                'body'                 => $in['question_body'],
                'category'             => $in['category_slug'],
                'product'              => $in['product_slug'],
                'jurisdiction'         => 'IN',
                'external_question_id' => 'routine:' . $in['external_key'],
                'author_reference'     => 'desk:' . $desk,
            ]);
            $this->intake->completeProcessing((int) $question['id']);

            $submissionId = $this->insertSubmission('faq', $in, (int) $question['id']);
            $answer       = $this->lifecycle->createDraft((string) $question['uuid'], $desk);
            $this->attachContent($submissionId, (int) $answer['id'], $in);

            $this->commit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        return ['status' => 'created'] + $this->process($submissionId);
    }

    /**
     * A desk answer to an existing question that has none yet — a real
     * member's question first of all.
     *
     * @throws InvalidArgumentException  invalid payload (422)
     * @throws CommunityQaRefusal        disabled (503), quota (429), unknown question (404), not answerable (409/422)
     */
    public function submitMemberAnswer(array $payload): array
    {
        $in = CommunityQaPayload::memberAnswer($payload);

        $this->assertEnabled();
        if (($existing = $this->findByKey($in['external_key'])) !== null) {
            // The default key is one per question; once that answer was not
            // published, the next answer gets the next key instead of being
            // told "already submitted" for ever.
            if (! ($in['key_defaulted'] ?? false) || ! in_array($existing['status'], ['withdrawn', 'failed'], true)) {
                return ['status' => 'already_submitted'] + $this->view($existing);
            }
            $in['external_key'] = $this->nextDefaultKey($in['external_key']);
            if (($existing = $this->findByKey($in['external_key'])) !== null && ! in_array($existing['status'], ['withdrawn', 'failed'], true)) {
                return ['status' => 'already_submitted'] + $this->view($existing);
            }
        }
        $this->assertQuota();

        $question = $this->questions->findByUuid($in['question_uuid']);
        if ($question === null) {
            throw new CommunityQaRefusal('Question not found: ' . $in['question_uuid'], 404);
        }
        // Settle it first: a question answered elsewhere since the routine
        // read the queue is closed as a duplicate here, not answered twice.
        $question = (new CommunityInboxAutomationService($this->db))->refreshQuestionId((int) $question['id']) ?? $question;
        if (($question['moderation_state'] ?? 'clean') !== 'clean' || PgBoolean::isTrue($question['personal_data_detected'] ?? false)) {
            throw new CommunityQaRefusal('This question is held for moderation or contains personal data; the desks do not answer it.', 422);
        }
        if (CommunityQuestionStatus::tryFrom((string) $question['status'])?->isTerminal() ?? false) {
            throw new CommunityQaRefusal('This question is archived or merged into another' . (! empty($question['handling_note']) ? ': ' . $question['handling_note'] : '.') . ' Skip it.', 409);
        }
        if (in_array($question['handled_by'] ?? null, [CommunityInboxPolicy::CLOSED, CommunityInboxPolicy::CLOSED_BY_PERSON], true)) {
            throw new CommunityQaRefusal('This question was closed: ' . ($question['handling_note'] ?? 'no reason recorded') . ' Skip it.', 409);
        }
        if ($this->hasOpenSubmissionFor((int) $question['id'])) {
            throw new CommunityQaRefusal('An answer to this question is already being vetted.', 409);
        }

        $live = (new \App\Models\CommunityOfficialAnswerModel())->findLiveByQuestionId((int) $question['id']);
        if ($live !== null && ! in_array(CommunityAnswerStatus::tryFrom((string) $live['status']), self::TAKE_OVER_STATUSES, true)) {
            throw new CommunityQaRefusal("This question already has an answer ({$live['status']}).", 409);
        }

        $in += [
            'question_title' => (string) $question['title'],
            'category_slug'  => (string) ($question['category'] ?? ''),
            'product_slug'   => $question['product'] ?? null,
        ];

        $this->db->transBegin();
        try {
            $submissionId = $this->insertSubmission('member_answer', $in, (int) $question['id']);
            $answer       = $live ?? $this->lifecycle->createDraft(
                (string) $question['uuid'],
                CommunityOperationalAgentService::deskFor($question['category'] ?? null, $question['product'] ?? null)
            );
            $this->attachContent($submissionId, (int) $answer['id'], $in);

            $this->commit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        return ['status' => 'created'] + $this->process($submissionId);
    }

    /** Commit, unless a statement failed quietly (DBDebug off), which rolls everything back. */
    private function commit(): void
    {
        if ($this->db->transStatus() === false) {
            throw new \RuntimeException('Could not store the submission: a database write failed.');
        }
        $this->db->transCommit();
    }

    // -------------------------------------------------------------------------
    // Vet → approve → publish
    // -------------------------------------------------------------------------

    /**
     * Take a submission as far as it can go now. Safe to call again: it picks
     * up from wherever the answer is (pending vetting, approved but not yet
     * live, published).
     */
    public function process(int $submissionId): array
    {
        $view = $this->advance($submissionId);

        // Keep the Question Inbox in step: vetting, published, or closed.
        $questionId = $this->submission($submissionId)['question_id'] ?? null;
        if ($questionId !== null) {
            try {
                (new CommunityInboxAutomationService($this->db))->refreshQuestionId((int) $questionId);
            } catch (Throwable $e) {
                log_message('warning', '[CommunityQaAutomation] inbox refresh failed for question ' . $questionId . ': ' . $e->getMessage());
            }
        }

        return $view;
    }

    private function advance(int $submissionId): array
    {
        $sub = $this->submission($submissionId);
        if ($sub === null) {
            throw new \RuntimeException("Community automation submission #{$submissionId} not found.");
        }
        if (! in_array($sub['status'], ['pending', 'approved'], true)) {
            return $this->view($sub);
        }

        $answer = $this->answers->findById((int) $sub['answer_id']);
        if ($answer === null) {
            return $this->finish($sub, 'failed', null, [], 'The answer record is missing.');
        }

        $status = CommunityAnswerStatus::from((string) $answer['status']);
        if ($status === CommunityAnswerStatus::Published) {
            return $this->confirmLive($sub, $answer);
        }
        if (in_array($status, [CommunityAnswerStatus::Approved, CommunityAnswerStatus::Scheduled], true)) {
            return $this->publish($sub, $answer);
        }
        if (in_array($status, [CommunityAnswerStatus::Publishing, CommunityAnswerStatus::VerificationFailed], true)) {
            // The deployment retry sweep owns this now; look again later, and
            // stop looking once it has given up.
            $deployment = $this->db->table('reach_community_deployments')
                ->select('status, last_error')
                ->where('answer_id', (int) $answer['id'])
                ->where('operation', 'publish')
                ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            if (($deployment['status'] ?? null) === 'dead_letter') {
                return $this->finish($sub, 'failed', null, null, 'Publication gave up after its retries: ' . ($deployment['last_error'] ?? 'unknown error'));
            }
            return $this->view($this->touch($sub, [
                'status'        => 'approved',
                'next_retry_at' => gmdate('Y-m-d H:i:sP', time() + 600),
            ]));
        }

        $tier = $this->lifecycle->riskTierOf($answer);
        if ($tier->isBlocked()) {
            return $this->withdraw($sub, $answer, 'blocked', null, [
                'Risk tier 4 (prohibited): this topic is never published by the desks.',
            ]);
        }

        $sub = $this->touch($sub, [
            'attempts'      => (int) $sub['attempts'] + 1,
            'next_retry_at' => gmdate('Y-m-d H:i:sP', time() + self::LEASE_SECONDS),
        ]);

        while (true) {
            $gate = $this->structuralGates($answer);
            if ($gate !== []) {
                return $this->withdraw($sub, $answer, 'withdrawn', null, $gate);
            }

            $draft = $this->draftOf($answer);
            try {
                $result = $this->vetting->vet('community', $draft, $this->context($sub, $answer, $tier), 'community_submission:' . $sub['id']);
            } catch (VettingUnavailableException $e) {
                return $this->leavePending($sub, $answer, $e->getMessage());
            }

            $sub = $this->recordRun($sub, $result);

            if ($result->rejected()) {
                return $this->withdraw($sub, $answer, 'withdrawn', VettingResult::REJECT, $result->reasons ?: ['Rejected by vetting.']);
            }

            if ($result->decision === VettingResult::REVISE) {
                if ((int) $sub['revisions'] >= self::MAX_REVISIONS) {
                    return $this->withdraw($sub, $answer, 'withdrawn', VettingResult::REVISE, array_merge(
                        ['Vetting still asked for changes after one revision, so it was not published.'],
                        $result->reasons
                    ));
                }
                $problem = $this->applyRevision($sub, $answer, $draft, $result->final);
                if ($problem !== null) {
                    return $this->withdraw($sub, $answer, 'withdrawn', VettingResult::REVISE, [$problem]);
                }
                $sub    = $this->touch($sub, ['revisions' => (int) $sub['revisions'] + 1, 'decision' => VettingResult::REVISE]);
                $answer = $this->answers->findById((int) $answer['id']) ?? $answer;
                continue;
            }

            return $this->approveAndPublish($sub, $answer, $tier, $result);
        }
    }

    /**
     * community:schedule — retry what vetting could not decide, and finish
     * what was approved but not yet confirmed live.
     *
     * @return array{retried: int, outcomes: list<array<string, mixed>>}
     */
    public function sweep(int $limit = 3): array
    {
        $now  = gmdate('Y-m-d H:i:sP');
        $rows = $this->db->table('reach_community_automation_submissions')
            ->whereIn('status', ['pending', 'approved'])
            ->groupStart()->where('next_retry_at IS NULL', null, false)->orWhere('next_retry_at <=', $now)->groupEnd()
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        $outcomes = [];
        foreach ($rows as $row) {
            try {
                $view = $this->process((int) $row['id']);
                $outcomes[] = ['submission_id' => (int) $row['id'], 'outcome' => $view['outcome'], 'decision' => $view['decision']];
            } catch (Throwable $e) {
                log_message('error', '[CommunityQaAutomation] sweep failed for submission ' . $row['id'] . ': ' . $e->getMessage());
                $this->touch($row, [
                    'last_error'    => mb_substr($e->getMessage(), 0, 1000),
                    'next_retry_at' => gmdate('Y-m-d H:i:sP', time() + self::backoffSeconds((int) $row['attempts'])),
                ]);
                $outcomes[] = ['submission_id' => (int) $row['id'], 'outcome' => 'error', 'error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        return ['retried' => count($rows), 'outcomes' => $outcomes];
    }

    /** Wait before re-vetting after the Nth unavailable attempt: 5 minutes doubling, capped at an hour. */
    public static function backoffSeconds(int $attempts): int
    {
        return (int) min(3600, 300 * (2 ** max(0, $attempts - 1)));
    }

    // -------------------------------------------------------------------------
    // Feed (Community → Automated feed)
    // -------------------------------------------------------------------------

    public function feed(int $limit = 50): array
    {
        $rows = $this->db->table('reach_community_automation_submissions s')
            ->select('s.*, q.uuid AS question_uuid, a.uuid AS answer_uuid, a.status AS answer_status, i.slug AS desk_slug, i.display_name AS desk_name')
            ->join('reach_community_questions q', 'q.id = s.question_id', 'left')
            ->join('reach_community_official_answers a', 'a.id = s.answer_id', 'left')
            ->join('reach_community_official_identities i', 'i.id = a.identity_id', 'left')
            ->orderBy('s.id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->get()->getResultArray();

        $limitPerDay = max(0, (int) ReachConfig::get('automation.community.daily_limit', 10));
        $used        = $this->usedToday();

        return [
            'summary' => [
                'enabled'         => (bool) ReachConfig::get('automation.community.enabled', true),
                'daily_limit'     => $limitPerDay,
                'used_today'      => $used,
                'remaining_today' => max(0, $limitPerDay - $used),
                'pending'         => $this->countByStatus('pending'),
                'retrying'        => (int) $this->db->table('reach_community_automation_submissions')
                    ->where('status', 'pending')->where('attempts >', 0)->countAllResults(),
                'approved'        => $this->countByStatus('approved'),
                'published'       => $this->countByStatus('published'),
                'withdrawn'       => $this->countByStatus('withdrawn'),
                'blocked'         => $this->countByStatus('blocked'),
                'failed'          => $this->countByStatus('failed'),
            ],
            'items' => array_map(fn (array $row): array => $this->view($row) + [
                'kind'           => $row['kind'],
                'question_title' => $row['question_title'],
                'category_slug'  => $row['category_slug'],
                'product_slug'   => $row['product_slug'],
                'desk'           => $row['desk_name'] ?? $row['desk_slug'],
                'answer_status'  => $row['answer_status'],
                'attempts'       => (int) $row['attempts'],
                'next_retry_at'  => $row['next_retry_at'],
                'last_error'     => $row['last_error'],
                'provenance'     => json_decode((string) $row['provenance_json'], true) ?: [],
                'created_at'     => $row['created_at'],
                'published_at'   => $row['published_at'],
            ], $rows),
        ];
    }

    // -------------------------------------------------------------------------
    // Steps
    // -------------------------------------------------------------------------

    private function insertSubmission(string $kind, array $in, int $questionId): int
    {
        $now = gmdate('Y-m-d H:i:sP');
        $this->db->table('reach_community_automation_submissions')->insert([
            'external_key'    => $in['external_key'],
            'kind'            => $kind,
            'question_id'     => $questionId,
            'status'          => 'pending',
            'category_slug'   => $in['category_slug'] ?: null,
            'product_slug'    => $in['product_slug'] ?? null,
            'question_title'  => mb_substr((string) $in['question_title'], 0, 512),
            'provenance_json' => json_encode($in['provenance'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $id = (int) $this->db->insertID();

        AuditLogger::record(AuditLogger::COMMUNITY_AUTOMATION_SUBMITTED, [
            'submission_id'  => $id,
            'kind'           => $kind,
            'question_id'    => $questionId,
            'generated_by'   => $in['provenance']['generated_by_model'],
            'routine_run_id' => $in['provenance']['routine_run_id'],
        ]);

        return $id;
    }

    /** The routine's text becomes the answer's next version, ready for the gates. */
    private function attachContent(int $submissionId, int $answerId, array $in): void
    {
        $this->answers->save(['id' => $answerId, 'ai_assisted' => true]);

        $this->versions->createVersion(
            $answerId,
            $in['answer_html'],
            $in['short_answer'],
            $in['sources'],
            $this->answers->getLatestVersion($answerId) === null ? 'initial' : 'edit',
            [
                'model_route'    => mb_substr('claude-routine:' . $in['provenance']['generated_by_model'], 0, 120),
                'prompt_version' => $in['provenance']['routine_run_id'] !== null ? mb_substr($in['provenance']['routine_run_id'], 0, 80) : null,
            ],
        );
        $this->moveAnswerTo($answerId, CommunityAnswerStatus::DraftGenerated);

        $answer = $this->answers->findById($answerId) ?? [];
        $this->db->table('reach_community_automation_submissions')->where('id', $submissionId)->update([
            'answer_id'  => $answerId,
            'risk_tier'  => $this->lifecycle->riskTierOf($answer)->value,
            'updated_at' => gmdate('Y-m-d H:i:sP'),
        ]);
    }

    /**
     * Validation (length, links, sanitiser) and moderation (PII, injection,
     * unsafe markup) for the answer's current version, run once per version.
     *
     * @return list<string> reasons it cannot be published; empty when it may proceed to vetting
     */
    private function structuralGates(array $answer): array
    {
        $uuid    = (string) $answer['uuid'];
        $version = $this->answers->getLatestVersion((int) $answer['id']);
        if ($version === null) {
            return ['The answer has no content.'];
        }

        if (($version['moderation_decision'] ?? 'pending') === 'pending') {
            $validation = $this->lifecycle->validate($uuid, (int) $version['version_number']);
            $moderation = $this->lifecycle->moderate($uuid, (int) $version['version_number']);
            $passed     = $validation['passed'];
            $findings   = array_merge($validation['findings'], $moderation['findings']);
            $blocked    = $moderation['blocked'];
        } else {
            $stored   = is_array($version['validation_results'] ?? null) ? $version['validation_results'] : [];
            $passed   = (bool) ($stored['passed'] ?? false);
            $findings = (array) ($stored['findings'] ?? []);
            $blocked  = $version['moderation_decision'] === 'blocked';
        }

        if ($passed && ! $blocked) {
            return [];
        }

        $reasons = [];
        foreach ($findings as $finding) {
            if (in_array($finding['severity'] ?? '', ['error', 'critical'], true)) {
                $reasons[] = (string) ($finding['detail'] ?? $finding['type'] ?? 'Failed an automatic check.');
            }
        }
        return $reasons ?: ['The answer failed an automatic content check.'];
    }

    /** @return array<string, string> */
    private function draftOf(array $answer): array
    {
        $question = $this->questions->findById((int) $answer['question_id']) ?? [];
        $version  = $this->answers->getLatestVersion((int) $answer['id']) ?? [];

        return [
            'question_title' => (string) ($question['title'] ?? ''),
            'question_body'  => (string) ($question['body'] ?? ''),
            'answer_html'    => (string) ($version['content'] ?? ''),
            'short_answer'   => (string) ($version['excerpt'] ?? ''),
        ];
    }

    /** Facts the vetting model may rely on. */
    private function context(array $sub, array $answer, CommunityRiskTier $tier): array
    {
        $member = $sub['kind'] === 'member_answer';

        return [
            'today'             => $this->today()->format('Y-m-d'),
            'country'           => 'India',
            'category'          => $sub['category_slug'],
            'asked_by'          => $member
                ? 'A member of the AICOUNTLY community. Keep their question in their own words (light grammar fixes and removing personal data only).'
                : 'The AICOUNTLY desk itself: an official FAQ post written for small businesses and accountants in India.',
            'answering_desk'    => (string) ($answer['identity_name'] ?? $answer['identity_slug'] ?? 'AICOUNTLY desk'),
            'risk_tier'         => $tier->value . ' — ' . $tier->label(),
            'product'           => $this->productFacts($sub['product_slug'] ?? null),
            'publication'       => 'Published on aicountly.com/community under the desk, labelled "AI-assisted · AICOUNTLY Desk". No person reviews it after you.',
            'allowed_html_tags' => config(\Config\ContentSanitization::class)->allowedTags,
        ];
    }

    /** @return array<string, mixed>|null */
    private function productFacts(?string $slug): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $facts = [
            'slug' => $slug,
            'name' => SaasProductTaxonomy::labelProduct($slug),
            'site' => SaasProductTaxonomy::productDedicatedSiteUrl($slug),
            'note' => 'The answer was written by a model that read the product source code. Remove or generalise any product claim that reads as a promise, a future feature, or is implausible.',
        ];

        try {
            $product = $this->db->table('reach_products')
                ->whereIn('slug', array_unique([$slug, str_replace('_', '-', $slug)]))
                ->where('deleted_at IS NULL', null, false)
                ->get()->getRowArray();
            if ($product !== null) {
                $facts['description'] = $product['short_description'] ?? null;
                $facts['features']    = array_column($this->db->table('reach_product_features f')
                    ->select('f.name')
                    ->join('reach_product_modules m', 'm.id = f.module_id')
                    ->where('m.product_id', (int) $product['id'])
                    ->where('f.status', 'approved')
                    ->limit(30)->get()->getResultArray(), 'name');
                $facts['approved_claims'] = array_column($this->db->table('reach_product_claims')
                    ->select('COALESCE(claim_summary, claim_text) AS claim', false)
                    ->where('product_id', (int) $product['id'])
                    ->where('status', 'approved')
                    ->limit(15)->get()->getResultArray(), 'claim');
            }
        } catch (Throwable $e) {
            log_message('warning', '[CommunityQaAutomation] product facts unavailable: ' . $e->getMessage());
        }

        return array_filter($facts, static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Apply the vetting model's corrections: question title/body edits and a
     * new answer version, which the gates and the re-vet then judge.
     *
     * @return string|null why the revision cannot be used, or null when applied
     */
    private function applyRevision(array $sub, array $answer, array $draft, array $final): ?string
    {
        $sanitizer = new HtmlSanitizer();

        $html = (string) ($final['answer_html'] ?? $draft['answer_html']);
        if ($sanitizer->wouldStripContent($html)) {
            return 'The vetting revision introduced markup the site does not allow.';
        }
        $html  = trim($sanitizer->purify($html));
        $short = trim($sanitizer->purifyText((string) ($final['short_answer'] ?? $draft['short_answer'])));
        $title = trim($sanitizer->purifyText((string) ($final['question_title'] ?? $draft['question_title'])));
        $body  = trim($sanitizer->purifyText((string) ($final['question_body'] ?? $draft['question_body'])));

        if (mb_strlen($title) < 10 || strlen($html) < CommunityQaPayload::MIN_ANSWER_CHARS) {
            return 'The vetting revision left the question or answer too short to publish.';
        }

        if ($title !== $draft['question_title'] || $body !== $draft['question_body']) {
            $this->questions->save([
                'id'    => (int) $answer['question_id'],
                'title' => mb_substr($title, 0, 255),
                'body'  => mb_substr($body, 0, 4000),
            ]);
            $this->db->table('reach_community_automation_submissions')->where('id', $sub['id'])
                ->update(['question_title' => mb_substr($title, 0, 512), 'updated_at' => gmdate('Y-m-d H:i:sP')]);
        }

        if ($html !== $draft['answer_html'] || $short !== $draft['short_answer']) {
            $previous = $this->answers->getLatestVersion((int) $answer['id']) ?? [];
            $this->versions->createVersion(
                (int) $answer['id'],
                $html,
                $short !== '' ? mb_substr($short, 0, 300) : $draft['short_answer'],
                is_array($previous['sources'] ?? null) ? $previous['sources'] : [],
                'edit',
                ['model_route' => mb_substr('vetting-revision:' . ReachConfig::vettingModel('community'), 0, 120)],
            );
            $this->moveAnswerTo((int) $answer['id'], CommunityAnswerStatus::DraftGenerated);
        }

        return null;
    }

    private function approveAndPublish(array $sub, array $answer, CommunityRiskTier $tier, VettingResult $result): array
    {
        $uuid = (string) $answer['uuid'];

        if (CommunityAnswerStatus::from((string) $answer['status']) !== CommunityAnswerStatus::EditorialReview
            && CommunityAnswerStatus::from((string) $answer['status']) !== CommunityAnswerStatus::ProfessionalReview
        ) {
            $this->moveAnswerTo((int) $answer['id'], CommunityAnswerStatus::DraftGenerated);
            $this->lifecycle->submitForReview($uuid);
        }

        // Tier 2/3 cannot carry a 'standard' approval (assertPublishableRiskState):
        // the vetting decision is recorded as the compliance review it is.
        $type   = $tier->requiresProfessionalApproval() ? 'compliance_review' : 'standard';
        $reason = sprintf(
            'Approved automatically by the Reach System Bot after AI vetting (run #%s, model %s, decision %s%s). No person reviewed this answer.',
            $result->runId ?? 'unrecorded',
            $result->model,
            $result->decision,
            (int) $sub['revisions'] > 0 ? ' after one revision' : ''
        );
        $this->lifecycle->approve($uuid, SystemApprover::userId(), null, $type, $reason, $result->runId);

        $decision = (int) $sub['revisions'] > 0 ? VettingResult::REVISE : VettingResult::APPROVE;
        $sub      = $this->touch($sub, [
            'status'        => 'approved',
            'decision'      => $decision,
            'reasons_json'  => json_encode($result->reasons, JSON_UNESCAPED_UNICODE),
            'next_retry_at' => null,
            'last_error'    => null,
        ]);

        AuditLogger::record(AuditLogger::COMMUNITY_AUTOMATION_DECIDED, [
            'submission_id'  => (int) $sub['id'],
            'answer_id'      => (int) $answer['id'],
            'decision'       => $decision,
            'approval_type'  => $type,
            'vetting_run_id' => $result->runId,
        ]);

        return $this->publish($sub, $this->answers->findById((int) $answer['id']) ?? $answer);
    }

    private function publish(array $sub, array $answer): array
    {
        try {
            $this->lifecycle->publish((string) $answer['uuid']);
        } catch (Throwable $e) {
            // A failed deployment is queued for the deployment retry sweep; a
            // refused one (risk gate, checksum) stays visible here.
            return $this->view($this->touch($sub, [
                'status'     => 'approved',
                'last_error' => mb_substr('Publication did not complete: ' . $e->getMessage(), 0, 1000),
            ]));
        }

        return $this->confirmLive($sub, $this->answers->findById((int) $answer['id']) ?? $answer);
    }

    /** Published in Reach; ask the public site whether it is actually live. */
    private function confirmLive(array $sub, array $answer): array
    {
        $live = false;
        try {
            $check = (new CommunityPublicationVerificationService())->verify((int) $answer['id']);
            $live  = ($check['details']['public_status'] ?? null) === 'published';
        } catch (Throwable $e) {
            log_message('warning', '[CommunityQaAutomation] live check failed for answer ' . $answer['id'] . ': ' . $e->getMessage());
        }

        $url = $answer['public_url'] ?? null;
        if (! $live && strtotime((string) $sub['created_at']) < time() - 2 * 86400) {
            return $this->finish($sub, 'failed', null, null, 'Published in Reach but never confirmed live on aicountly.com within 48 hours.', ['public_url' => $url]);
        }
        if (! $live) {
            return $this->view($this->touch($sub, [
                'status'        => 'approved',
                'public_url'    => $url,
                'last_error'    => 'Published, but the public site does not report it live yet; will check again.',
                'next_retry_at' => gmdate('Y-m-d H:i:sP', time() + 600),
            ]));
        }

        return $this->finish($sub, 'published', $sub['decision'] ?? VettingResult::APPROVE, null, null, [
            'public_url'   => $url,
            'published_at' => gmdate('Y-m-d H:i:sP'),
        ]);
    }

    private function leavePending(array $sub, array $answer, string $error): array
    {
        $attempts = (int) $sub['attempts'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->withdraw($sub, $answer, 'failed', null, [
                "Vetting was unavailable for {$attempts} attempts, so this was never published. Last error: " . mb_substr($error, 0, 300),
            ]);
        }

        AuditLogger::record(AuditLogger::COMMUNITY_AUTOMATION_PENDING, [
            'submission_id' => (int) $sub['id'],
            'attempts'      => $attempts,
        ]);

        return $this->view($this->touch($sub, [
            'status'        => 'pending',
            'last_error'    => mb_substr($error, 0, 1000),
            'next_retry_at' => gmdate('Y-m-d H:i:sP', time() + self::backoffSeconds($attempts)),
        ]));
    }

    /**
     * Not publishable: retire the answer (and a routine-written question with
     * it) so nothing half-done lingers, and keep the reasons. A member's
     * question stays open for another answer.
     */
    private function withdraw(array $sub, array $answer, string $status, ?string $decision, array $reasons): array
    {
        try {
            $this->moveAnswerTo((int) $answer['id'], CommunityAnswerStatus::Archived);
            if ($sub['kind'] === 'faq') {
                $this->moveQuestionTo((int) $answer['question_id'], CommunityQuestionStatus::Archived);
            }
        } catch (Throwable $e) {
            log_message('error', '[CommunityQaAutomation] could not retire answer ' . $answer['id'] . ': ' . $e->getMessage());
        }

        AuditLogger::record(AuditLogger::COMMUNITY_AUTOMATION_DECIDED, [
            'submission_id' => (int) $sub['id'],
            'answer_id'     => (int) $answer['id'],
            'decision'      => $decision ?? $status,
            'reasons'       => array_slice($reasons, 0, 5),
        ]);

        return $this->finish($sub, $status, $decision, $reasons);
    }

    private function finish(array $sub, string $status, ?string $decision, ?array $reasons = null, ?string $error = null, array $extra = []): array
    {
        $changes = ['status' => $status, 'next_retry_at' => null, 'last_error' => $error] + $extra;
        if ($decision !== null) {
            $changes['decision'] = $decision;
        }
        if ($reasons !== null) {
            $changes['reasons_json'] = json_encode(array_values($reasons), JSON_UNESCAPED_UNICODE);
        }
        return $this->view($this->touch($sub, $changes));
    }

    private function recordRun(array $sub, VettingResult $result): array
    {
        $runs = json_decode((string) ($sub['vetting_run_ids'] ?? '[]'), true) ?: [];
        if ($result->runId !== null) {
            $runs[] = $result->runId;
        }
        return $this->touch($sub, [
            'vetting_run_ids' => json_encode($runs),
            'reasons_json'    => json_encode($result->reasons, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // -------------------------------------------------------------------------
    // State-machine helpers
    // -------------------------------------------------------------------------

    /** Walk the answer state machine to $target along the shortest legal path. */
    private function moveAnswerTo(int $answerId, CommunityAnswerStatus $target): void
    {
        $answer = $this->answers->findById($answerId);
        $from   = CommunityAnswerStatus::from((string) ($answer['status'] ?? ''));
        foreach (self::path($from, $target, [CommunityAnswerStatus::class, 'validTransitions']) as [$a, $b]) {
            $this->answers->transitionStatus($answerId, $a, $b);
        }
    }

    private function moveQuestionTo(int $questionId, CommunityQuestionStatus $target): void
    {
        $question = $this->questions->findById($questionId);
        $from     = CommunityQuestionStatus::from((string) ($question['status'] ?? ''));
        foreach (self::path($from, $target, [CommunityQuestionStatus::class, 'validTransitions']) as [$a, $b]) {
            $this->questions->transitionStatus($questionId, $a, $b);
        }
    }

    /**
     * Breadth-first search over a status enum's transition table.
     *
     * @template T of \UnitEnum
     * @param T $from
     * @param T $to
     * @return list<array{0: T, 1: T}>
     */
    public static function path(\UnitEnum $from, \UnitEnum $to, callable $transitions): array
    {
        if ($from === $to) {
            return [];
        }
        $queue = [[$from, []]];
        $seen  = [$from->name => true];
        while ($queue !== []) {
            [$node, $steps] = array_shift($queue);
            foreach ($transitions($node) as $next) {
                if (isset($seen[$next->name])) {
                    continue;
                }
                $path = [...$steps, [$node, $next]];
                if ($next === $to) {
                    return $path;
                }
                $seen[$next->name] = true;
                $queue[]           = [$next, $path];
            }
        }
        throw new \RuntimeException("No legal status path from {$from->name} to {$to->name}.");
    }

    // -------------------------------------------------------------------------
    // Guards and lookups
    // -------------------------------------------------------------------------

    private function assertEnabled(): void
    {
        if (! (bool) ReachConfig::get('automation.community.enabled', true)) {
            throw new CommunityQaRefusal('Community Q&A automation is switched off (Settings → AI & Automation). Stop this run.', 503);
        }
    }

    private function assertQuota(): void
    {
        $limit = max(0, (int) ReachConfig::get('automation.community.daily_limit', 10));
        $used  = $this->usedToday();
        if ($used >= $limit) {
            throw new CommunityQaRefusal(
                "Today's community Q&A quota is used ({$used} of {$limit}; resets at midnight IST). Stop this run.",
                429
            );
        }
    }

    private function assertNotDuplicateTitle(string $title): void
    {
        $normalised = self::normaliseTitle($title);
        foreach ($this->recentTitles(2000) as $existing) {
            if (self::normaliseTitle($existing) === $normalised) {
                throw new CommunityQaRefusal('A question with this title already exists. Pick a different question.', 409);
            }
        }
    }

    public static function normaliseTitle(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($title)) ?? '');
    }

    private function usedToday(): int
    {
        return (int) $this->db->table('reach_community_automation_submissions')
            ->where('created_at >=', $this->today()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'))
            ->countAllResults();
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone(self::IST));
    }

    private function countByStatus(string $status): int
    {
        return (int) $this->db->table('reach_community_automation_submissions')->where('status', $status)->countAllResults();
    }

    private function hasOpenSubmissionFor(int $questionId): bool
    {
        return $this->db->table('reach_community_automation_submissions')
            ->where('question_id', $questionId)
            ->whereIn('status', ['pending', 'approved'])
            ->countAllResults() > 0;
    }

    /** @return list<string> */
    private function recentTitles(int $limit): array
    {
        return array_column($this->db->table('reach_community_questions')
            ->select('title')
            ->whereNotIn('status', [CommunityQuestionStatus::Archived->value, CommunityQuestionStatus::DuplicateMerged->value])
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray(), 'title');
    }

    /**
     * The routine's queue: CommunityInboxAutomationService::pending(), which
     * settles the inbox first (published, duplicates and unanswerable
     * questions closed, stuck legacy drafts handed over) and then lists the
     * questions waiting for an answer, members' first.
     *
     * @return list<array<string, mixed>>
     */
    private function openQuestions(int $limit): array
    {
        return (new CommunityInboxAutomationService($this->db))->pending($limit);
    }

    /** member-answer:<uuid> → member-answer:<uuid>:2, :3 … — the first key not used by an unsuccessful answer. */
    private function nextDefaultKey(string $key): string
    {
        for ($n = 2; $n < 20; $n++) {
            $candidate = $key . ':' . $n;
            $row       = $this->findByKey($candidate);
            if ($row === null || ! in_array($row['status'], ['withdrawn', 'failed'], true)) {
                return $candidate;
            }
        }
        return $key . ':' . $n;
    }

    /** @return list<array<string, mixed>> */
    private function mixWithRecentCounts(): array
    {
        $since  = gmdate('Y-m-d H:i:sP', time() - 7 * 86400);
        $counts = [];
        foreach ($this->db->table('reach_community_automation_submissions')
            ->select('category_slug, product_slug, COUNT(*) AS n')
            ->where('created_at >=', $since)
            ->whereIn('status', ['pending', 'approved', 'published'])
            ->groupBy(['category_slug', 'product_slug'])
            ->get()->getResultArray() as $row) {
            $counts[($row['category_slug'] ?? '') . '|' . ($row['product_slug'] ?? '')] = (int) $row['n'];
        }

        return array_map(static function (array $target) use ($counts): array {
            $products = $target['key'] === 'productivity' ? ['notes', 'drive'] : [$target['product_slug']];
            $n        = 0;
            foreach ($products as $product) {
                $n += $counts[$target['category_slug'] . '|' . ($product ?? '')] ?? 0;
            }
            return $target + ['last_7_days' => $n];
        }, self::MIX_TARGETS);
    }

    private function findByKey(string $key): ?array
    {
        return $this->db->table('reach_community_automation_submissions')->where('external_key', $key)->get()->getRowArray() ?: null;
    }

    private function submission(int $id): ?array
    {
        return $this->db->table('reach_community_automation_submissions')->where('id', $id)->get()->getRowArray() ?: null;
    }

    private function touch(array $sub, array $changes): array
    {
        $changes['updated_at'] = gmdate('Y-m-d H:i:sP');
        $this->db->table('reach_community_automation_submissions')->where('id', (int) $sub['id'])->update($changes);
        return $this->submission((int) $sub['id']) ?? array_merge($sub, $changes);
    }

    /** What the routine and the feed see for one submission. */
    private function view(array $sub): array
    {
        $questionUuid = $sub['question_uuid'] ?? (
            $sub['question_id'] !== null ? ($this->questions->findById((int) $sub['question_id'])['uuid'] ?? null) : null
        );
        $answerUuid = $sub['answer_uuid'] ?? (
            $sub['answer_id'] !== null ? ($this->answers->findById((int) $sub['answer_id'])['uuid'] ?? null) : null
        );

        return [
            'submission_id' => (int) $sub['id'],
            'external_key'  => $sub['external_key'],
            'question_id'   => $questionUuid,
            'answer_id'     => $answerUuid,
            'decision'      => $sub['decision'] ?? ($sub['status'] === 'pending' ? 'pending' : $sub['status']),
            'outcome'       => $sub['status'],
            'reasons'       => json_decode((string) ($sub['reasons_json'] ?? '[]'), true) ?: [],
            'risk_tier'     => $sub['risk_tier'] !== null ? (int) $sub['risk_tier'] : null,
            'public_url'    => $sub['public_url'] ?? null,
            'vetting_runs'  => json_decode((string) ($sub['vetting_run_ids'] ?? '[]'), true) ?: [],
        ];
    }
}
