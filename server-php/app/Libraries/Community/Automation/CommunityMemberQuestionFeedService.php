<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Libraries\AuditLogger;
use App\Libraries\Community\CommunityPublisherFactory;
use App\Libraries\Community\CommunityPublisherInterface;
use App\Libraries\Community\CommunityQuestionIntakeService;
use App\Libraries\Community\CommunityQuestionRepository;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Members' questions from aicountly.com/community, into the Question Inbox.
 *
 * Pulls the receiver's signed member-questions listing (the same HMAC trust as
 * every other call to the site; the site never calls Reach). Each question:
 *
 *   new, published, not yet answered officially → normal intake as
 *     public_submission (personal-data screen, classification, triage,
 *     duplicate check) → the inbox automation → the routine's pending queue.
 *     Its answer is published onto that same thread (the provisioner passes
 *     public_question_id instead of creating a desk FAQ page);
 *   edited before it was answered → Reach's copy follows the member's words
 *     and is screened again (personal data, then the vetting model);
 *   edited after it was answered → noted; the published answer stays;
 *   deleted by the member / hidden by moderation / answered officially or
 *     accepted on the site → closed by the bot with that reason.
 *
 * Idempotent on the site's post id (external_question_id "aicountly:<id>")
 * and its content checksum. The cursor is kept in settings and only moves
 * past rows that were handled.
 */
class CommunityMemberQuestionFeedService
{
    public const SOURCE_TYPE   = 'public_submission';
    private const CURSOR_KEY   = 'community_member_questions_cursor';
    private const PAGE_SIZE    = 100;
    private const MAX_PAGES    = 5;
    private const HIDDEN       = ['hidden', 'removed', 'unpublished', 'withdrawn', 'draft'];

    private BaseConnection $db;
    private CommunityPublisherInterface $publisher;

    public function __construct(
        ?CommunityPublisherInterface $publisher = null,
        ?BaseConnection $db = null,
        private readonly SettingModel $settings = new SettingModel(),
        private readonly CommunityQuestionIntakeService $intake = new CommunityQuestionIntakeService(),
        private readonly CommunityQuestionRepository $questions = new CommunityQuestionRepository(),
    ) {
        $this->publisher = $publisher ?? CommunityPublisherFactory::create();
        $this->db        = $db ?? Database::connect();
    }

    public static function externalIdFor(int $publicQuestionId): string
    {
        return 'aicountly:' . $publicQuestionId;
    }

    /** @return array<string, mixed> */
    public function sync(int $maxPages = self::MAX_PAGES): array
    {
        $cursor  = $this->cursor();
        $summary = ['pages' => 0, 'seen' => 0, 'ingested' => 0, 'updated' => 0, 'closed' => 0, 'noted' => 0, 'skipped' => 0, 'errors' => 0];

        for ($page = 0; $page < $maxPages; $page++) {
            $res = $this->publisher->getMemberQuestions($cursor['since'], $cursor['after_id'], self::PAGE_SIZE);
            if (! ($res['success'] ?? false)) {
                // Not configured, or the site is down: nothing ingested, the
                // cursor stays, the next tick asks again. Said, not hidden.
                return $summary + ['error' => (string) ($res['safe_error_message'] ?? $res['error_category'] ?? 'member-questions call failed'), 'cursor' => $cursor];
            }
            $summary['pages']++;

            foreach ((array) ($res['questions'] ?? []) as $item) {
                $summary['seen']++;
                try {
                    $summary[$this->apply((array) $item)]++;
                } catch (Throwable $e) {
                    // One bad row must not stall the feed behind it: it is
                    // logged and passed; an edit to it re-sends it.
                    $summary['errors']++;
                    log_message('error', '[CommunityMemberFeed] question ' . ($item['public_question_id'] ?? '?') . ': ' . $e->getMessage());
                }
            }

            $next = (array) ($res['next_cursor'] ?? []);
            if (isset($next['since'])) {
                $cursor = ['since' => (string) $next['since'], 'after_id' => (int) ($next['after_id'] ?? 0)];
                $this->settings->setSetting(self::CURSOR_KEY, $cursor);
            }
            if (! ($res['has_more'] ?? false)) {
                break;
            }
        }

        if ($summary['ingested'] + $summary['updated'] + $summary['closed'] > 0) {
            AuditLogger::record(AuditLogger::COMMUNITY_MEMBER_FEED_SYNCED, $summary);
        }

        return $summary + ['cursor' => $cursor];
    }

    /** @return string summary key */
    private function apply(array $item): string
    {
        $postId = (int) ($item['public_question_id'] ?? 0);
        if ($postId <= 0) {
            return 'skipped';
        }

        $status   = strtolower((string) ($item['status'] ?? 'published'));
        $existing = $this->db->table('reach_community_questions')
            ->where('source_type', self::SOURCE_TYPE)
            ->where('external_question_id', self::externalIdFor($postId))
            ->get()->getRowArray();

        if ($existing === null) {
            if ($status !== 'published' || ! empty($item['has_official_answer']) || ! empty($item['has_accepted_answer'])) {
                return 'skipped'; // nothing for the desks to do
            }
            return $this->ingest($postId, $item);
        }

        $sync = [
            'source_status'    => $status,
            'source_synced_at' => gmdate('Y-m-d H:i:sP'),
            'public_url'       => (string) ($item['canonical_url'] ?? $existing['public_url'] ?? ''),
        ];
        $inbox = new CommunityInboxAutomationService($this->db);

        if ($status === 'deleted' || in_array($status, self::HIDDEN, true)) {
            $this->db->table('reach_community_questions')->where('id', (int) $existing['id'])->update($sync);
            $closed = $status === 'deleted'
                ? $inbox->closeForSource((int) $existing['id'], 'removed_by_member', 'Closed: the member deleted the question on aicountly.com.')
                : $inbox->closeForSource((int) $existing['id'], 'hidden_on_site', "Closed: the question is {$status} on aicountly.com (moderation).");
            return $closed ? 'closed' : 'skipped';
        }

        // Answered on the site by staff, or the member accepted a reply, before the desks got to it.
        if ((! empty($item['has_official_answer']) && ($existing['handled_by'] ?? null) !== CommunityInboxPolicy::PUBLISHED
                && ! $this->reachAnswered((int) $existing['id']))
            || (! empty($item['has_accepted_answer']) && ! $this->reachAnswered((int) $existing['id']))
        ) {
            $this->db->table('reach_community_questions')->where('id', (int) $existing['id'])->update($sync);
            return $inbox->closeForSource((int) $existing['id'], 'answered_on_site', 'Closed: already answered on aicountly.com.') ? 'closed' : 'skipped';
        }

        $checksum = (string) ($item['content_checksum'] ?? '');
        if ($checksum === '' || $checksum === (string) ($existing['source_checksum'] ?? '')) {
            $this->db->table('reach_community_questions')->where('id', (int) $existing['id'])->update($sync);
            return 'skipped';
        }

        // The member's words changed.
        $sync['source_checksum'] = $checksum;
        if ($this->reachAnswered((int) $existing['id'])
            || in_array($existing['handled_by'] ?? null, [CommunityInboxPolicy::PUBLISHED, CommunityInboxPolicy::CLOSED, CommunityInboxPolicy::CLOSED_BY_PERSON], true)
        ) {
            $sync['handling_note'] = mb_substr(trim((string) ($existing['handling_note'] ?? '')) . ' Edited by the member on aicountly.com on ' . gmdate('d-m-Y') . '; nothing was changed here.', 0, 2000);
            $this->db->table('reach_community_questions')->where('id', (int) $existing['id'])->update($sync);
            return 'noted';
        }

        [$title, $body] = $this->text($item);
        $this->db->table('reach_community_questions')->where('id', (int) $existing['id'])->update($sync + [
            'title'                 => $title,
            'body'                  => $body,
            // Screen the new words again: personal data, then the vetting model.
            'triage_decision'       => null,
            'triage_vetting_run_id' => null,
            'triage_checked_at'     => null,
            'automation_failures'   => 0,
            'handling_note'         => 'Edited by the member on aicountly.com; screened again before the routine answers it.',
            'handling_updated_at'   => gmdate('Y-m-d H:i:sP'),
            'updated_at'            => gmdate('Y-m-d H:i:sP'),
        ]);
        $this->intake->completeProcessing((int) $existing['id']);
        $inbox->refreshQuestionId((int) $existing['id']);

        return 'updated';
    }

    private function ingest(int $postId, array $item): string
    {
        [$title, $body] = $this->text($item);
        if (mb_strlen($title) < 5) {
            return 'skipped';
        }

        $question = $this->intake->intake([
            'source_type'          => self::SOURCE_TYPE,
            'title'                => $title,
            'body'                 => $body,
            'category'             => $item['category_slug'] ?? null,
            'tags'                 => array_slice(array_map('strval', (array) ($item['tags'] ?? [])), 0, 10),
            'source_url'           => $item['canonical_url'] ?? null,
            'external_question_id' => self::externalIdFor($postId),
            'question_timestamp'   => $item['created_at'] ?? null,
            'jurisdiction'         => 'IN',
            // No member identity crosses over: the desks answer the question, not the person.
        ]);

        $this->db->table('reach_community_questions')->where('id', (int) $question['id'])->update([
            'public_external_id' => (string) $postId,
            'public_url'         => (string) ($item['canonical_url'] ?? ''),
            'source_checksum'    => (string) ($item['content_checksum'] ?? ''),
            'source_status'      => 'published',
            'source_synced_at'   => gmdate('Y-m-d H:i:sP'),
        ]);

        // Personal-data screen, classification, triage score, duplicate check;
        // then the inbox decides (queue, duplicate, closed).
        $this->intake->completeProcessing((int) $question['id']);
        (new CommunityInboxAutomationService($this->db))->refreshQuestionId((int) $question['id']);

        return 'ingested';
    }

    /** @return array{0: string, 1: string} */
    private function text(array $item): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($item['title'] ?? ''))) ?? '');
        $body  = trim(strip_tags((string) ($item['body'] ?? '')));

        return [mb_substr($title, 0, 512), mb_substr($body, 0, 8000)];
    }

    private function reachAnswered(int $questionId): bool
    {
        return $this->db->table('reach_community_official_answers')
            ->where('question_id', $questionId)
            ->whereIn('status', ['approved', 'scheduled', 'publishing', 'published', 'verification_failed'])
            ->countAllResults() > 0;
    }

    /** @return array{since: string, after_id: int} */
    private function cursor(): array
    {
        $stored = $this->settings->getSetting(self::CURSOR_KEY);
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (is_array($stored) && isset($stored['since'])) {
            return ['since' => (string) $stored['since'], 'after_id' => (int) ($stored['after_id'] ?? 0)];
        }

        // First run: every member question ever asked, so the ones already
        // waiting are answered too.
        return ['since' => '2000-01-01T00:00:00Z', 'after_id' => 0];
    }
}
