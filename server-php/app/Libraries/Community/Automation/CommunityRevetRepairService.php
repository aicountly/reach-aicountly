<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\AuditLogger;
use App\Libraries\Community\CommunityPublisherFactory;
use App\Libraries\Community\CommunityPublisherInterface;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * community:revet-repair — what a re-vet correction that never reached
 * aicountly.com left behind, and the one-off clean-up.
 *
 * Until the fix of 23 Oct every correction of an already-published answer
 * dead-lettered on the question upsert (the receiver refused a reused
 * idempotency key with a new body). The pipeline then did what it is meant
 * to: CommunityReanswerQueue::onNotPublished put the answer back as it was
 * (published, previous approved version) and re-queued the question for the
 * reach-community-qa routine. Nothing on the site changed — the 409 came
 * before any write there.
 *
 * Per answer it reports Reach's state, the latest publish deployment, the
 * latest re-vet submission, the re-answer request and (unless --no-remote)
 * what the site says. With apply it:
 *   - restore: puts back an answer still not published in Reach after its
 *     correction gave up, only when the site confirms the answer is published
 *     there and nothing for its question is being vetted or published now;
 *   - release: cancels the re-answer request such a failure left queued, so
 *     `reach:content-revet --community --answer-ids=…` vets the live answer
 *     again (Gemini's revision then goes out through the fixed path) instead
 *     of waiting for the routine to write a new answer.
 * Dry run by default; running it again finds nothing left to do.
 */
final class CommunityRevetRepairService
{
    public const GAVE_UP_PREFIX = 'Replacement failed: Publication gave up';
    public const RELEASE_NOTE   = 'Released for re-vetting by community:revet-repair: the correction was approved but never published (receiver idempotency conflict, fixed 23 Oct).';

    private BaseConnection $db;
    private CommunityReanswerQueue $queue;

    public function __construct(?BaseConnection $db = null, private ?CommunityPublisherInterface $publisher = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->queue = new CommunityReanswerQueue($this->db);
    }

    /** Answers whose re-vet correction gave up at publication. @return list<int> */
    public function affectedAnswerIds(): array
    {
        $rows = $this->db->table('reach_community_automation_submissions')
            ->distinct()->select('answer_id')
            ->where('origin', 'revet')->where('status', 'failed')
            ->like('last_error', 'Publication gave up', 'after')
            ->where('answer_id IS NOT NULL', null, false)
            ->orderBy('answer_id', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['answer_id'], $rows);
    }

    /**
     * @param list<int> $answerIds empty = affectedAnswerIds()
     * @return array{dry_run: bool, items: list<array<string, mixed>>, released: list<int>} released: released
     *         now (apply) or that would be (dry run) — the ids to re-vet next
     */
    public function run(array $answerIds, bool $apply, bool $remote = true): array
    {
        $ids   = $answerIds !== [] ? array_values(array_unique(array_map('intval', $answerIds))) : $this->affectedAnswerIds();
        $items = [];
        $released = [];
        foreach ($ids as $id) {
            $item = $this->inspect($id, $remote);
            if ($apply) {
                $item = $this->repair($item);
            }
            if (in_array('release', $apply ? ($item['done'] ?? []) : $item['actions'], true)) {
                $released[] = $id;
            }
            unset($item['_reanswer']);
            $items[] = $item;
        }

        return ['dry_run' => ! $apply, 'items' => $items, 'released' => $released];
    }

    /** @return array<string, mixed> */
    private function inspect(int $answerId, bool $remote): array
    {
        $answer = $this->db->table('reach_community_official_answers')->where('id', $answerId)->get()->getRowArray();
        if ($answer === null) {
            return ['answer_id' => $answerId, 'exists' => false, 'actions' => [], 'note' => 'No such answer.', '_reanswer' => null];
        }

        $deployment = $this->db->table('reach_community_deployments')
            ->select('id, answer_version_number, status, attempt_count, max_attempts, last_error, last_error_category, public_url, updated_at')
            ->where('answer_id', $answerId)->where('operation', 'publish')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $submission = $this->db->table('reach_community_automation_submissions')
            ->select('id, kind, origin, status, last_error, updated_at')
            ->where('answer_id', $answerId)->where('origin', 'revet')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $reanswer = $this->queue->available()
            ? ($this->db->table(CommunityReanswerQueue::TABLE)->where('answer_id', $answerId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray() ?: null)
            : null;
        $inFlight = $this->db->table('reach_community_automation_submissions')
            ->where('question_id', (int) $answer['question_id'])->whereIn('status', ['pending', 'approved'])->countAllResults();

        $site = null;
        if ($remote) {
            try {
                $r    = $this->publisher()->getAnswerStatus((string) $answer['uuid']);
                $site = ($r['success'] ?? false)
                    ? ['public_status' => $r['public_status'] ?? null, 'public_version' => $r['public_version'] ?? null, 'revision_number' => $r['revision_number'] ?? null, 'canonical_url' => $r['canonical_url'] ?? null]
                    : ['error' => (string) ($r['remote_error_code'] ?? $r['error_category'] ?? 'error') . ': ' . ($r['safe_error_message'] ?? '')];
            } catch (Throwable $e) {
                $site = ['error' => $e->getMessage()];
            }
        }

        $published   = $answer['status'] === CommunityAnswerStatus::Published->value;
        $siteLive    = ($site['public_status'] ?? null) === 'published';
        $snapshot    = json_decode((string) ($reanswer['replaced_state'] ?? ''), true) ?: [];
        $gaveUp      = $reanswer !== null && str_starts_with((string) ($reanswer['last_error'] ?? ''), self::GAVE_UP_PREFIX);
        $actions     = [];
        $note        = null;

        if (! $published && $inFlight === 0 && $reanswer !== null && $reanswer['status'] !== 'submitted'
            && ($snapshot['status'] ?? null) === CommunityAnswerStatus::Published->value) {
            if ($siteLive) {
                $actions[] = 'restore';
            } else {
                $note = 'Not published in Reach and the site did not confirm it is live (' . ($site === null ? 'not checked' : json_encode($site)) . '); left as is.';
            }
        }
        if ($gaveUp && $reanswer['status'] === 'queued' && ($published || in_array('restore', $actions, true))) {
            $actions[] = 'release';
        }

        return [
            'answer_id'          => $answerId,
            'exists'             => true,
            'uuid'               => (string) $answer['uuid'],
            'status'             => (string) $answer['status'],
            'publication_status' => $answer['publication_status'] ?? null,
            'approved_version'   => $answer['approved_version'] !== null ? (int) $answer['approved_version'] : null,
            'current_version'    => $answer['current_version'] !== null ? (int) $answer['current_version'] : null,
            'correction_state'   => $answer['correction_state'] ?? null,
            'public_url'         => $answer['public_url'] ?? null,
            'deployment'         => $deployment,
            'submission'         => $submission,
            'reanswer'           => $reanswer === null ? null : [
                'id' => (int) $reanswer['id'], 'status' => $reanswer['status'], 'mode' => $reanswer['mode'],
                'attempts' => (int) $reanswer['attempts'], 'last_error' => $reanswer['last_error'],
            ],
            'in_flight'          => $inFlight,
            'site'               => $site,
            'actions'            => $actions,
            'note'               => $note,
            '_reanswer'          => $reanswer,
        ];
    }

    /** @param array<string, mixed> $item */
    private function repair(array $item): array
    {
        $item['done'] = [];
        $row = $item['_reanswer'] ?? null;
        if ($row === null) {
            return $item;
        }
        if (in_array('restore', $item['actions'], true) && $this->queue->restoreReplacedState($row)) {
            $item['done'][] = 'restore';
            $item['status'] = (string) ($this->db->table('reach_community_official_answers')->select('status')->where('id', $item['answer_id'])->get()->getRow()->status ?? $item['status']);
        }
        if (in_array('release', $item['actions'], true)) {
            $now = gmdate('Y-m-d H:i:sP');
            $this->db->table(CommunityReanswerQueue::TABLE)->where('id', (int) $row['id'])->where('status', 'queued')
                ->update(['status' => 'cancelled', 'last_error' => self::RELEASE_NOTE, 'completed_at' => $now, 'updated_at' => $now]);
            if ($this->db->affectedRows() > 0) {
                $item['done'][] = 'release';
            }
        }
        if ($item['done'] !== []) {
            AuditLogger::record('community.answer.revet_repair', [
                'answer_id' => $item['answer_id'], 'reanswer_id' => (int) $row['id'], 'done' => $item['done'],
            ]);
        }

        return $item;
    }

    private function publisher(): CommunityPublisherInterface
    {
        return $this->publisher ??= CommunityPublisherFactory::create();
    }
}
