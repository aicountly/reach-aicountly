<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

/**
 * Who handles a Question Inbox row next, decided from what Reach already
 * knows about it. Pure: CommunityInboxAutomationService gathers the facts and
 * applies the decision, so the rules can be read (and tested) in one place.
 *
 * Nobody in the inbox is waiting on a person. Every open question is either
 * offered to the reach-community-qa routine, being vetted/published, published,
 * or closed by the bot with its reason.
 */
final class CommunityInboxPolicy
{
    public const QUEUE     = 'routine_queue';
    public const VETTING   = 'routine_vetting';
    public const PUBLISHING = 'publishing';
    public const PUBLISHED = 'published';
    public const CLOSED    = 'auto_closed';
    /** Archived or merged by a person (or before this existed); nothing automatic reopens it. */
    public const CLOSED_BY_PERSON = 'closed';

    public const HANDLERS = [self::QUEUE, self::VETTING, self::PUBLISHING, self::PUBLISHED, self::CLOSED, self::CLOSED_BY_PERSON];

    /** Unsuccessful routine answers (withdrawn or failed) before the bot stops offering a question. */
    public const MAX_ROUTINE_ATTEMPTS = 2;

    /** Failed screens (classification or triage) before the bot stops retrying a question. */
    public const MAX_AUTOMATION_FAILURES = 3;

    public const SPAM_THRESHOLD = 0.6;

    public const QUEUED_NOTE = 'Queued for the community-qa routine.';

    /** A question rests this long after a withdrawn answer before it is offered again. */
    public const REST_SECONDS = 86400;

    /** Legacy answer statuses nothing automatic moves any more (see CommunityLegacyDraftService). */
    public const STUCK_ANSWER_STATUSES = [
        'draft_requested', 'generating', 'draft_generated', 'validation_failed',
        'changes_requested', 'moderation_required', 'editorial_review', 'professional_review',
    ];

    /** Answer statuses with no content a routine answer can take over at once. */
    private const TAKE_OVER_WHEN_EMPTY = ['draft_requested', 'validation_failed'];

    /** Answer statuses the publisher (deployment retry, verification) owns. */
    private const PUBLISHING_STATUSES = ['approved', 'scheduled', 'publishing', 'verification_failed', 'restoring'];

    /**
     * @param array{
     *   status: string,
     *   source_type?: string,
     *   moderation_state?: string,
     *   personal_data_detected?: bool,
     *   spam_score?: float,
     *   classified?: bool,
     *   automation_failures?: int,
     *   triage_decision?: ?string,
     *   triage_reasons?: list<string>,
     *   published_answer?: ?array{url: ?string},
     *   live_answer?: ?array{status: string, versions: int, age_seconds: int},
     *   inflight?: ?array{status: string},
     *   failed_submissions?: int,
     *   blocked_submissions?: int,
     *   last_reasons?: list<string>,
     *   seconds_since_last_failure?: ?int,
     *   duplicate_of?: ?array{id: int, title: string, url: ?string},
     * } $f
     * @return array{action: string, handled_by: string, note: string, close_reason?: string}
     *   action: publish | close | release | queue | wait | vetting | publishing | label_closed
     */
    public static function decide(array $f, int $stuckHours): array
    {
        $status = (string) $f['status'];

        if (! empty($f['published_answer'])) {
            $url = $f['published_answer']['url'] ?? null;
            return ['action' => 'publish', 'handled_by' => self::PUBLISHED,
                'note' => 'Answered and published on aicountly.com/community' . ($url ? ': ' . $url : '.')];
        }

        if (in_array($status, ['archived', 'duplicate_merged'], true)) {
            $byBot = (int) ($f['failed_submissions'] ?? 0) + (int) ($f['blocked_submissions'] ?? 0) > 0;
            $why   = self::reasonText($f['last_reasons'] ?? []);
            return ['action' => 'label_closed', 'handled_by' => $byBot ? self::CLOSED : self::CLOSED_BY_PERSON,
                'note' => $byBot
                    ? 'Not published: vetting withdrew the answer' . ($why !== '' ? ' — ' . $why : '.')
                    : ($status === 'duplicate_merged' ? 'Merged into another question.' : 'Archived.')];
        }

        if (! empty($f['inflight'])) {
            return $f['inflight']['status'] === 'approved'
                ? ['action' => 'vetting', 'handled_by' => self::PUBLISHING, 'note' => 'Approved after vetting; publishing and checking it is live.']
                : ['action' => 'vetting', 'handled_by' => self::VETTING, 'note' => 'The routine has answered; the vetting model is checking the answer.'];
        }

        if (! empty($f['live_answer']) && in_array($f['live_answer']['status'], self::PUBLISHING_STATUSES, true)) {
            return ['action' => 'publishing', 'handled_by' => self::PUBLISHING, 'note' => 'The answer is approved; the publisher is putting it live (' . $f['live_answer']['status'] . ').'];
        }

        if (! empty($f['personal_data_detected'])) {
            return self::close('personal_data', 'Closed: the question contains personal data, so the desks do not answer it publicly.');
        }
        if (($f['moderation_state'] ?? 'clean') !== 'clean') {
            return self::close('moderation', 'Closed: the question was flagged by moderation (' . $f['moderation_state'] . ').');
        }
        if ((float) ($f['spam_score'] ?? 0) >= self::SPAM_THRESHOLD) {
            return self::close('spam', 'Closed as spam (spam score ' . number_format((float) $f['spam_score'], 2) . ').');
        }
        if (array_key_exists('classified', $f) && ! $f['classified'] && (int) ($f['automation_failures'] ?? 0) >= self::MAX_AUTOMATION_FAILURES) {
            return self::close('unscreenable', 'Closed: the personal-data screen failed ' . (int) $f['automation_failures'] . ' times, so it is not answered.');
        }
        if (array_key_exists('classified', $f) && ! $f['classified']) {
            return ['action' => 'wait', 'handled_by' => self::QUEUE,
                'note' => 'Waiting for the personal-data screen before it is offered to the routine.'];
        }
        if ((int) ($f['blocked_submissions'] ?? 0) > 0) {
            return self::close('prohibited', 'Closed: the topic is prohibited for the desks (risk tier 4).');
        }
        if (($f['triage_decision'] ?? null) === 'reject') {
            $why = self::reasonText($f['triage_reasons'] ?? []);
            return self::close('rejected', 'Closed by the vetting model' . ($why !== '' ? ': ' . $why : '.'));
        }
        if ((int) ($f['failed_submissions'] ?? 0) >= self::MAX_ROUTINE_ATTEMPTS) {
            $why = self::reasonText($f['last_reasons'] ?? []);
            return self::close('unanswerable', 'Closed after ' . (int) $f['failed_submissions'] . ' answers were not published' . ($why !== '' ? ' — ' . $why : '.'));
        }
        if (! empty($f['duplicate_of'])) {
            $d = $f['duplicate_of'];
            return self::close('duplicate', 'Already answered: “' . $d['title'] . '”' . (! empty($d['url']) ? ' — ' . $d['url'] : '') . '.');
        }

        $live = $f['live_answer'] ?? null;
        if ($live !== null && in_array($live['status'], self::STUCK_ANSWER_STATUSES, true)
            && ! (in_array($live['status'], self::TAKE_OVER_WHEN_EMPTY, true) && (int) $live['versions'] === 0)
        ) {
            $wait = max(0, $stuckHours * 3600 - (int) $live['age_seconds']);
            if ($wait === 0) {
                return ['action' => 'release', 'handled_by' => self::QUEUE,
                    'note' => "Old in-Reach draft ({$live['status']}) archived after {$stuckHours} h; queued for the community-qa routine."];
            }
            return ['action' => 'wait', 'handled_by' => self::QUEUE,
                'note' => "Old in-Reach draft ({$live['status']}); handed to the community-qa routine in " . self::hours($wait) . '.'];
        }

        $since = $f['seconds_since_last_failure'] ?? null;
        if ($since !== null && $since < self::REST_SECONDS) {
            return ['action' => 'queue', 'handled_by' => self::QUEUE,
                'note' => 'Queued for the community-qa routine; offered again 24 h after an answer that was not published.'];
        }

        return ['action' => 'queue', 'handled_by' => self::QUEUE, 'note' => self::QUEUED_NOTE];
    }

    /** @return array{action: string, handled_by: string, note: string, close_reason: string} */
    private static function close(string $reason, string $note): array
    {
        return ['action' => 'close', 'handled_by' => self::CLOSED, 'note' => $note, 'close_reason' => $reason];
    }

    /** @param list<string> $reasons */
    private static function reasonText(array $reasons): string
    {
        $text = trim(implode(' ', array_slice(array_map('strval', $reasons), 0, 2)));
        return mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 297)) . '…' : $text;
    }

    private static function hours(int $seconds): string
    {
        $h = (int) ceil($seconds / 3600);
        return $h <= 1 ? 'under an hour' : "about {$h} h";
    }
}
