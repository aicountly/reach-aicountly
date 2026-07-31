<?php

namespace App\Libraries\Community;

use App\Libraries\AuditLogger;
use App\Models\SettingModel;

/**
 * Pulls the public site's own "what changed" feed
 * (CommunityReceiverController::listChanges, /community/changes) into Reach —
 * the "sync-public"/"sync-moderation" direction: moderation actions and
 * status changes on public-facing content originate on the public site
 * (real users reporting, public moderators acting), not in Reach, so Reach
 * must periodically pull rather than assume it already knows.
 *
 * This is intentionally detection-only: it never overwrites Reach's own
 * status columns (those are governed by OfficialAnswerRepository's strict
 * transition table) or moderation-decides anything by itself. It surfaces
 * drift as an audit log entry for a human/CommunityPublicationReconciliationService
 * follow-up to act on.
 */
class CommunityChangeSyncService
{
    private const CURSOR_SETTING_KEY = 'community_sync_last_since';
    private const DEFAULT_LOOKBACK_HOURS = 24;

    /** Public answer states that mean "Reach's 'published' record no longer matches reality". */
    private const PROBLEM_ANSWER_STATUSES = ['unpublished', 'withdrawn', 'removed', 'deleted'];
    private const PROBLEM_COMMENT_STATUSES = ['removed', 'hidden', 'flagged'];

    private CommunityPublisherInterface $publisher;
    private SettingModel $settings;

    public function __construct(?CommunityPublisherInterface $publisher = null, ?SettingModel $settings = null)
    {
        $this->publisher = $publisher ?? CommunityPublisherFactory::create();
        $this->settings  = $settings ?? new SettingModel();
    }

    /**
     * @return array{since:string, questions_checked:int, answers_checked:int, comments_checked:int, drift_detected:int}
     */
    public function sync(int $limit = 500): array
    {
        $since = (string) ($this->settings->getSetting(self::CURSOR_SETTING_KEY)
            ?? gmdate('c', time() - self::DEFAULT_LOOKBACK_HOURS * 3600));

        $result = $this->publisher->getChanges($since, $limit);
        if (! ($result['success'] ?? false)) {
            AuditLogger::record(AuditLogger::COMMUNITY_SYNC_DRIFT_DETECTED, [
                'reason' => 'sync_call_failed',
                'since'  => $since,
            ]);
            return ['since' => $since, 'questions_checked' => 0, 'answers_checked' => 0, 'comments_checked' => 0, 'drift_detected' => 0];
        }

        $questions = $result['questions'] ?? [];
        $answers   = $result['answers'] ?? [];
        $comments  = $result['comments'] ?? [];

        $driftCount = 0;
        foreach ($answers as $answer) {
            if (self::isAnswerDrift($answer)) {
                $driftCount++;
                AuditLogger::record(AuditLogger::COMMUNITY_SYNC_DRIFT_DETECTED, [
                    'entity_type'    => 'answer',
                    'reach_external_id' => $answer['reach_external_id'] ?? null,
                    'public_status'  => $answer['status'] ?? null,
                ]);
            }
        }

        $db = db_connect();
        foreach ($comments as $comment) {
            if (! self::isCommentDrift($comment)) {
                continue;
            }
            $externalId = (string) ($comment['reach_external_id'] ?? '');
            if ($externalId === '') {
                continue;
            }
            // Only bot-facilitated comments are ours to care about here —
            // a removed human comment is not a Reach concern.
            $wasOurs = $db->table('reach_community_agent_runs')
                ->where('target_external_ref', $externalId)
                ->where('action', 'post_comment')
                ->countAllResults() > 0;
            if ($wasOurs) {
                $driftCount++;
                AuditLogger::record(AuditLogger::COMMUNITY_SYNC_DRIFT_DETECTED, [
                    'entity_type'        => 'comment',
                    'reach_external_id'  => $externalId,
                    'public_status'      => $comment['status'] ?? null,
                    'note'               => 'facilitator-authored comment was moderated on the public site',
                ]);
            }
        }

        $newCursor = $this->latestTimestamp($since, $questions, $answers, $comments);
        $this->settings->setSetting(self::CURSOR_SETTING_KEY, $newCursor);

        $summary = [
            'since'             => $since,
            'questions_checked' => count($questions),
            'answers_checked'   => count($answers),
            'comments_checked'  => count($comments),
            'drift_detected'    => $driftCount,
        ];

        AuditLogger::record(AuditLogger::COMMUNITY_SYNC_COMPLETED, $summary);

        return $summary;
    }

    public static function isAnswerDrift(array $answer): bool
    {
        return in_array((string) ($answer['status'] ?? ''), self::PROBLEM_ANSWER_STATUSES, true);
    }

    public static function isCommentDrift(array $comment): bool
    {
        return in_array((string) ($comment['status'] ?? ''), self::PROBLEM_COMMENT_STATUSES, true);
    }

    private function latestTimestamp(string $current, array ...$sets): string
    {
        $latest = strtotime($current) ?: time();
        foreach ($sets as $rows) {
            foreach ($rows as $row) {
                $ts = strtotime((string) ($row['updated_at'] ?? ''));
                if ($ts !== false && $ts > $latest) {
                    $latest = $ts;
                }
            }
        }
        return gmdate('c', $latest);
    }
}
