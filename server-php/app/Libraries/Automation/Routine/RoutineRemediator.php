<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\AuditLogger;
use App\Libraries\Content\Quality\RegenerationIngestService;
use App\Libraries\Content\Quality\RegenerationQueue;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What to do about a routine blog / help article that stopped on its way to
 * aicountly.com — and doing it, by the bot on its own (sweep(), every five
 * minutes after the pipeline sweep) or when a person presses the button.
 *
 * The ladder, one step per pass, each recorded on the submission:
 *
 *   retry    the cause was outside the text (the public site refused it, a
 *            connection was down, vetting was unavailable): push it again.
 *   revet    the text no longer matches its approval (it was edited after
 *            vetting), or the publish gate's second Gemini check refused it:
 *            send the CURRENT text back through vetting, which applies
 *            Gemini's corrections and binds a fresh approval to that version.
 *   rewrite  vetting still refuses it: hand the topic and Gemini's reasons to
 *            the blog / KB routine as a same-slug rewrite
 *            (reach_content_regenerations); the old text is no longer retried.
 *   person   nothing safe is left for a bot: a person revoked the approval,
 *            Gemini held it as high risk, or MAX_STEPS steps did not get it out.
 *
 * Nothing here publishes unvetted text: retry and revet both go through the
 * same vetting + checksum-bound approval as any routine article.
 */
final class RoutineRemediator
{
    /** Bot steps per submission before it is left for a person. */
    public const MAX_STEPS = 4;
    /** Infrastructure retries of a publish_failed row before a person looks. */
    private const MAX_RETRIES = 2;
    /** Wait this long before retrying a publish_failed row (the site / network may need time). */
    private const RETRY_COOLDOWN_SECS = 6 * 3600;
    /** A row the bot just touched is left alone for this long. */
    private const STEP_COOLDOWN_SECS = 30 * 60;

    private const CONTENT_KINDS = ['vet_refused', 'not_approved', 'version_mismatch'];
    private const INFRA_KINDS   = ['site_refused', 'publish_refused', 'vetting_unavailable'];

    public const STEP_LABELS = [
        'retry'   => 'Retry publishing',
        'revet'   => 'Re-vet the current text and publish',
        'rewrite' => 'Ask the bot to rewrite it (same URL)',
    ];

    private BaseConnection $db;
    private RoutineSubmissions $submissions;

    public function __construct(
        ?BaseConnection $db = null,
        private ?RoutineContentPipeline $pipeline = null,
        ?RoutineSubmissions $submissions = null,
        private ?RegenerationQueue $queue = null,
    ) {
        $this->db          = $db ?? Database::connect();
        $this->submissions = $submissions ?? new RoutineSubmissions($this->db);
    }

    public static function autoEnabled(): bool
    {
        return (bool) ReachConfig::get('automation.remediation.auto_fix_blocked', true);
    }

    /**
     * Why publishing stopped. Rows written before block_kind existed are
     * classified from their last_error.
     *
     * @param array<string, mixed> $row
     */
    public static function blockKind(array $row): ?string
    {
        if (! empty($row['block_kind'])) {
            return (string) $row['block_kind'];
        }
        $e = (string) ($row['last_error'] ?? '');
        if ($e === '') {
            return null;
        }

        return match (true) {
            str_contains($e, 'no longer matches')              => 'version_mismatch',
            str_contains($e, 'approval was revoked')           => 'approval_revoked',
            str_contains($e, 'Vetting is unavailable')         => 'vetting_unavailable',
            str_contains($e, 'the vetting model')              => 'vet_refused',
            str_contains($e, 'needs a valid approval'),
            str_contains($e, 'Only the current version'),
            str_contains($e, 'must be approved')               => 'not_approved',
            str_contains($e, 'Public site refused'),
            str_contains($e, 'Deployment record missing')      => 'site_refused',
            str_contains($e, 'auto-publish is off')            => 'auto_publish_off',
            default                                            => 'publish_refused',
        };
    }

    /**
     * The step the bot takes next, and a sentence saying why.
     *
     * @param array<string, mixed> $row
     * @return array{step: string, reason: string}
     */
    public static function diagnose(array $row): array
    {
        $status = (string) $row['status'];
        $count  = (int) ($row['remediation_count'] ?? 0);
        $last   = $row['remediation_step'] ?? null;
        $kind   = self::blockKind($row);

        if ($status === RoutineSubmissions::AWAITING_REWRITE) {
            return ['step' => 'wait', 'reason' => 'The routine writes the replacement on its next run; Gemini vets it like any new article and it publishes at the same URL.'];
        }
        if ($status === RoutineSubmissions::REJECTED) {
            // A text the bot sent back to vetting and Gemini then rejected:
            // the next step is a fresh rewrite. Rejections of new routine
            // drafts are final — the content base offers the topic again.
            return $count > 0 && $count < self::MAX_STEPS
                ? ['step' => 'rewrite', 'reason' => 'Gemini rejected the re-vetted text, so the bot asks the routine for a fresh rewrite on the same topic and URL, with Gemini\'s reasons.']
                : ['step' => 'none', 'reason' => ''];
        }
        if ($status === RoutineSubmissions::HELD_HIGH_RISK) {
            return ['step' => 'person', 'reason' => 'Gemini flagged it high risk, so the bot does not publish it on its own. Approve it in the Verification Queue, or ask the bot for a rewrite.'];
        }
        if (! in_array($status, RoutineSubmissions::BLOCKED, true)) {
            return ['step' => 'none', 'reason' => ''];
        }
        if ($kind === 'approval_revoked') {
            return ['step' => 'person', 'reason' => 'A person revoked the approval, so the bot leaves it alone. Re-vet it or ask for a rewrite if it should go out.'];
        }
        if ($kind === 'rewrite_failed') {
            return ['step' => 'person', 'reason' => 'The routine\'s rewrites did not get through vetting; a person must decide — edit it, or retire the topic.'];
        }
        if ($kind === 'auto_publish_off') {
            return ['step' => 'person', 'reason' => 'Auto-publish is off; switch it on in Settings → AI & Automation or publish it by hand.'];
        }
        if ($count >= self::MAX_STEPS) {
            return ['step' => 'person', 'reason' => "The bot tried {$count} fixes and it is still not out — it needs a person."];
        }

        if (in_array($kind, self::INFRA_KINDS, true) || $kind === null) {
            if ($status === RoutineSubmissions::PUBLISH_BLOCKED) {
                return ['step' => 'wait', 'reason' => 'Publishing is retried automatically with a back-off; nothing in the text needs fixing.'];
            }
            $retries = $last === 'retry' ? $count : 0;

            return $retries < self::MAX_RETRIES
                ? ['step' => 'retry', 'reason' => 'The cause was outside the text (' . str_replace('_', ' ', (string) $kind) . '); the bot retries it after a pause.']
                : ['step' => 'person', 'reason' => 'Publishing kept failing for a reason outside the text — check the publication connection and the Job monitor.'];
        }

        // A content problem (vet_refused, not_approved, version_mismatch).
        return match ($last) {
            'revet'   => ['step' => 'rewrite', 'reason' => 'Re-vetting did not get it through, so the bot asks the routine for a fresh rewrite on the same topic and URL, with Gemini\'s reasons.'],
            'rewrite' => ['step' => 'revet', 'reason' => 'The rewrite was refused at publication; the bot re-vets it once more so Gemini can correct it.'],
            default   => ['step' => 'revet', 'reason' => $kind === 'vet_refused'
                ? 'Gemini\'s publication check refused this text; the bot re-vets it, which applies Gemini\'s corrections and approves the corrected version.'
                : 'The text changed after it was approved; the bot re-vets the current text so the approval matches what goes out.'],
        };
    }

    /**
     * For the screens: what the bot will do, what it did, and which buttons apply.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function describe(array $row): array
    {
        $status = (string) $row['status'];
        $d      = self::diagnose($row);

        return [
            'next_step'   => $d['step'],
            'reason'      => $d['reason'],
            'block_kind'  => self::blockKind($row),
            'steps_taken' => (int) ($row['remediation_count'] ?? 0),
            'last_step'   => $row['remediation_step'] ?? null,
            'last_at'     => $row['remediated_at'] ?? null,
            'available'   => array_values(array_filter([
                in_array($status, RoutineSubmissionView::RETRYABLE, true) ? 'retry' : null,
                in_array($status, RoutineSubmissions::BLOCKED, true) ? 'revet' : null,
                in_array($status, [...RoutineSubmissions::BLOCKED, RoutineSubmissions::HELD_HIGH_RISK], true)
                    || ($status === RoutineSubmissions::REJECTED && (int) ($row['remediation_count'] ?? 0) > 0) ? 'rewrite' : null,
            ])),
        ];
    }

    /**
     * Take one step now (a person pressed a button, or the bot's sweep).
     *
     * @return array<string, mixed> the submission row after the step
     * @throws RemediationRefused when the step does not apply to this row
     */
    public function apply(int $id, string $step, string $actor = 'operator', ?int $userId = null, ?string $note = null): array
    {
        $row = $this->submissions->find($id);
        if ($row === null) {
            throw new RemediationRefused('Routine submission not found.', 404);
        }
        $allowed = self::describe($row)['available'];
        if (! in_array($step, $allowed, true)) {
            throw new RemediationRefused(sprintf("'%s' does not apply to a submission that is '%s'.", self::STEP_LABELS[$step] ?? $step, $row['status']), 409);
        }

        $after = match ($step) {
            'retry'   => $this->pipeline()->retry($id),
            'revet'   => $this->revet($row),
            'rewrite' => $this->rewrite($row, $note),
        };
        if (! empty($after['busy'])) {
            throw new RemediationRefused('This submission is being processed right now; try again in a few minutes.', 409);
        }

        $this->db->table('reach_routine_submissions')->where('id', $id)->update([
            'remediation_count' => (int) ($row['remediation_count'] ?? 0) + 1,
            'remediation_step'  => $step,
            'remediated_at'     => date('Y-m-d H:i:s'),
        ]);
        AuditLogger::record('routine.remediated', [
            'submission_id' => $id, 'content_item_id' => (int) $row['content_item_id'], 'step' => $step, 'actor' => $actor,
            'from' => $row['status'], 'to' => $after['status'] ?? null, 'block_kind' => self::blockKind($row),
        ], $userId);

        return $this->submissions->find($id) ?? $after;
    }

    /**
     * The bot's own pass: take the next step on every blocked article that is
     * due, and close rewrites the routine gave up on.
     *
     * @return array{considered: int, steps: array<string, int>, errors: int}
     */
    public function sweep(int $limit = 10, ?\DateTimeImmutable $now = null): array
    {
        $out = ['considered' => 0, 'steps' => [], 'errors' => 0];
        if (! self::autoEnabled()) {
            return $out + ['skipped' => 'automation.remediation.auto_fix_blocked is off'];
        }
        $now = $now ?? new \DateTimeImmutable('now');
        $this->closeAbandonedRewrites();

        $rows = $this->db->table('reach_routine_submissions')
            ->groupStart()
                ->whereIn('status', RoutineSubmissions::BLOCKED)
                ->orGroupStart()->where('status', RoutineSubmissions::REJECTED)->where('remediation_count >', 0)->groupEnd()
            ->groupEnd()
            ->where('remediation_count <', self::MAX_STEPS)
            ->groupStart()->where('locked_until IS NULL', null, false)->orWhere('locked_until <', $now->format('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('id', 'ASC')->limit(max(1, $limit) * 3)
            ->get()->getResultArray();

        foreach ($rows as $row) {
            if ($out['considered'] >= $limit) {
                break;
            }
            $step = self::diagnose($row)['step'];
            if (! in_array($step, ['retry', 'revet', 'rewrite'], true) || ! $this->due($row, $step, $now)) {
                continue;
            }
            $out['considered']++;
            try {
                $this->apply((int) $row['id'], $step, 'bot');
                $out['steps'][$step] = ($out['steps'][$step] ?? 0) + 1;
            } catch (\Throwable $e) {
                $out['errors']++;
                log_message('error', "[RoutineRemediator] submission {$row['id']} {$step}: " . $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * "Fix them now" from the Command centre: the next step on every blocked
     * article, without waiting for the bot's pauses.
     *
     * @return array{considered: int, steps: array<string, int>, left_for_a_person: int, errors: list<string>}
     */
    public function fixAllNow(?int $userId, int $limit = 10): array
    {
        $out  = ['considered' => 0, 'steps' => [], 'left_for_a_person' => 0, 'errors' => []];
        $rows = $this->db->table('reach_routine_submissions')->whereIn('status', RoutineSubmissions::BLOCKED)
            ->orderBy('id', 'ASC')->limit(max(1, min(25, $limit)))->get()->getResultArray();
        foreach ($rows as $row) {
            $out['considered']++;
            $step = self::diagnose($row)['step'];
            if (! in_array($step, ['retry', 'revet', 'rewrite'], true)) {
                $out['left_for_a_person'] += $step === 'person' ? 1 : 0;
                continue;
            }
            try {
                $this->apply((int) $row['id'], $step, 'operator', $userId);
                $out['steps'][$step] = ($out['steps'][$step] ?? 0) + 1;
            } catch (\Throwable $e) {
                $out['errors'][] = "#{$row['id']}: " . $e->getMessage();
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function due(array $row, string $step, \DateTimeImmutable $now): bool
    {
        $ts = $now->getTimestamp();
        if (! empty($row['remediated_at']) && strtotime((string) $row['remediated_at']) > $ts - self::STEP_COOLDOWN_SECS) {
            return false;
        }
        if ($step === 'retry') {
            return strtotime((string) $row['updated_at']) <= $ts - self::RETRY_COOLDOWN_SECS;
        }

        return true;
    }

    /**
     * Send the current text back through vetting: Gemini approves it (with
     * its corrections applied) and a fresh approval is bound to that version,
     * or rejects it. Then run the pipeline straight on to publication.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function revet(array $row): array
    {
        $id     = (int) $row['id'];
        $itemId = (int) $row['content_item_id'];
        if (! $this->submissions->claim($id, 2)) {
            return $row + ['busy' => true];
        }
        try {
            $this->submissions->update($id, [
                'status'           => RoutineSubmissions::PENDING_VETTING,
                'decision'         => null,
                'approved_by'      => null,
                'approved_at'      => null,
                'approval_id'      => null,
                'final_checksum'   => null,
                'final_version_id' => null,
                'vetting_attempts' => 0,
                'publish_attempts' => 0,
                'next_attempt_at'  => null,
                'block_kind'       => null,
                'last_error'       => 'Re-vetting the current text (requested by the remediation step).',
            ]);
            $this->db->table('reach_content_items')->where('id', $itemId)->update(['approval_status' => 'pending', 'updated_at' => date('Y-m-d H:i:s')]);
            (new RegenerationIngestService($this->db, $this->submissions))->resetWorkflow((string) $row['content_type'], $itemId);
        } finally {
            $this->submissions->release($id);
        }

        return $this->pipeline()->process($id);
    }

    /**
     * Hand the topic to the routine as a same-slug rewrite, with everything
     * vetting said about the current text.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rewrite(array $row, ?string $note): array
    {
        $id      = (int) $row['id'];
        $itemId  = (int) $row['content_item_id'];
        $queue   = $this->queue ??= new RegenerationQueue($this->db);
        $reasons = array_values(array_filter(array_map('strval', [
            $note !== null && trim($note) !== '' ? 'Owner note: ' . mb_substr(trim($note), 0, 500) : null,
            ! empty($row['last_error']) ? mb_substr((string) $row['last_error'], 0, 600) : null,
            ...array_slice((array) ($row['reasons_json'] ?? []), 0, 8),
        ])));
        if ($reasons === []) {
            $reasons = ['The earlier text could not be published; write it afresh.'];
        }

        $open = $queue->openForItem($itemId);
        if ($open !== null && $open['status'] === 'submitted') {
            // An earlier rewrite got in but was refused at publication: offer it again.
            $queue->requeueAfterRejection($itemId, $reasons);
            $open = $queue->openForItem($itemId);
            if ($open === null) {
                $this->submissions->update($id, [
                    'status'     => RoutineSubmissions::PUBLISH_FAILED,
                    'block_kind' => 'rewrite_failed',
                    'last_error' => 'The routine has rewritten this ' . RegenerationQueue::MAX_CLAIMS . ' times without it getting through vetting; a person must decide.',
                ]);

                return $this->submissions->find($id) ?? $row;
            }
        }
        $wasLive = ! empty($row['verified_at']) || ! empty($row['published_at']);
        $regenId = $open !== null ? (int) $open['id'] : $queue->enqueue($itemId, $reasons, $wasLive);
        if ($regenId === null) {
            throw new RemediationRefused('Only blog posts and help articles can be rewritten by the routine.', 422);
        }

        $routine = $row['content_type'] === 'blog' ? 'blog routine (hourly, 09:00–20:00 IST)' : 'help-article routine (10:00 and 16:00 IST)';
        $this->submissions->update($id, [
            'status'          => RoutineSubmissions::AWAITING_REWRITE,
            'next_attempt_at' => null,
            'last_error'      => "Rewrite requested (regeneration #{$regenId}): the {$routine} writes it on its next run; it is vetted like any new article and returns at the same URL.",
        ]);

        return $this->submissions->find($id) ?? $row;
    }

    /** A rewrite the routine gave up on (MAX_CLAIMS refusals) goes to a person. */
    private function closeAbandonedRewrites(): void
    {
        foreach ($this->db->query(
            "SELECT s.id FROM reach_routine_submissions s
              WHERE s.status = 'awaiting_rewrite'
                AND NOT EXISTS (SELECT 1 FROM reach_content_regenerations r
                                 WHERE r.content_item_id = s.content_item_id AND r.status IN ('queued','claimed','submitted'))"
        )->getResultArray() as $r) {
            $this->submissions->update((int) $r['id'], [
                'status'           => RoutineSubmissions::PUBLISH_FAILED,
                'block_kind'       => 'rewrite_failed',
                'remediation_count'=> self::MAX_STEPS,
                'last_error'       => 'The routine\'s rewrites did not get through vetting; a person must decide (edit it, or retire the topic).',
            ]);
        }
    }

    private function pipeline(): RoutineContentPipeline
    {
        return $this->pipeline ??= new RoutineContentPipeline(null, null, $this->submissions);
    }
}
