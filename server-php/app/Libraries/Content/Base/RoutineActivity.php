<?php

declare(strict_types=1);

namespace App\Libraries\Content\Base;

use App\Libraries\Database\SchemaGuard;
use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * When did a routine last touch Reach, per content type?
 *
 * Two sources, newest wins:
 *   - reach_routine_activity: every authenticated v1/automation/* call,
 *     recorded by AutomationTokenFilter::after() (plans, submissions,
 *     content-base calls) — catches a routine that runs but finds nothing to do;
 *   - the submission tables — so the signal is right from the first deploy,
 *     before any call was recorded.
 *
 * A type is `stale` when nothing happened for longer than its threshold
 * (daily routines: automation.content_base.stale_hours, default 36 h; weekly
 * ones: 8 days). Informational only — shown on the Content Base page and in
 * the daily report; nobody has to act on it for content to keep flowing.
 */
final class RoutineActivity
{
    /** type => [routine name, cadence] */
    public const ROUTINES = [
        'blog'            => ['reach-blog', 'daily'],
        'knowledge_base'  => ['reach-kb', 'daily'],
        'community'       => ['reach-community-qa', 'daily'],
        'content_base'    => ['reach-content-base', 'daily'],
        'release_note'    => ['reach-release-notes', 'daily'],
        'video'           => ['reach-video', 'daily'],
        'workflow_guide'  => ['reach-workflow-guides', 'weekly'],
        'growth_strategy' => ['reach-growth-strategy', 'weekly'],
    ];

    /** first path segment after v1/automation/ => content type */
    private const PATH_TYPES = [
        'blog-plan' => 'blog', 'blog-drafts' => 'blog',
        'kb-plan' => 'knowledge_base', 'kb-drafts' => 'knowledge_base',
        'community-plan' => 'community', 'community-qa' => 'community', 'community-answers' => 'community',
        'release-plan' => 'release_note', 'release-notes' => 'release_note',
        'workflow-plan' => 'workflow_guide', 'workflow-guides' => 'workflow_guide',
        'video-plan' => 'video', 'video-scripts' => 'video',
        'insights-digest' => 'growth_strategy', 'strategy-memos' => 'growth_strategy',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Map a request path to a content type; null for shared endpoints (link
     * registry, gallery…). $typeParam is accepted for future use.
     *
     * @return array{0:string, 1:string}|null [content_type, endpoint]
     */
    public static function classify(string $path, ?string $typeParam): ?array
    {
        $path = trim($path, '/');
        $pos  = strpos($path, 'automation/');
        if ($pos === false) {
            return null;
        }
        $rest     = substr($path, $pos + strlen('automation/'));
        $endpoint = substr($rest, 0, 80);
        $first    = explode('/', $rest)[0];

        if (isset(self::PATH_TYPES[$first])) {
            return [self::PATH_TYPES[$first], $endpoint];
        }
        if ($first === 'content-base') {
            // Every routine reads the queue (and the curator reads all types),
            // so these calls prove only that *a* routine is alive; the writers
            // are credited through their own plan / submission endpoints.
            return ['content_base', $endpoint];
        }

        return null;
    }

    public function record(string $contentType, string $endpoint, string $method, int $status): void
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_routine_activity')) {
            return;
        }
        $this->db->query(
            'INSERT INTO reach_routine_activity (content_type, endpoint, method, hits, last_status, first_at, last_at)
             VALUES (?, ?, ?, 1, ?, NOW(), NOW())
             ON CONFLICT (content_type, endpoint, method)
             DO UPDATE SET hits = reach_routine_activity.hits + 1, last_status = EXCLUDED.last_status, last_at = NOW()',
            [$contentType, mb_substr($endpoint, 0, 80), strtoupper(mb_substr($method, 0, 8)), $status]
        );
    }

    /**
     * @return array<string, array<string,mixed>> per content type
     */
    public function health(?\DateTimeImmutable $now = null): array
    {
        $now       = $now ?? new \DateTimeImmutable('now');
        $dailyHrs  = max(1, (int) ReachConfig::get('automation.content_base.stale_hours', 36));
        $api       = $this->apiActivity();
        $submitted = $this->lastSubmissions();
        $out       = [];

        foreach (self::ROUTINES as $type => [$routine, $cadence]) {
            $threshold = $cadence === 'weekly' ? 192 : $dailyHrs;
            $apiAt     = $api[$type]['last_at'] ?? null;
            $subAt     = $submitted[$type] ?? null;
            $last      = self::latest($apiAt, $subAt);
            $hours     = $last !== null ? round(($now->getTimestamp() - strtotime($last)) / 3600, 1) : null;
            $enabled   = self::enabled($type);

            $out[$type] = [
                'routine'          => $routine,
                'cadence'          => $cadence,
                'enabled'          => $enabled,
                'last_activity_at' => $last,
                'last_api_call_at' => $apiAt,
                'last_api_endpoint'=> $api[$type]['endpoint'] ?? null,
                'last_submission_at' => $subAt,
                'hours_since'      => $hours,
                'threshold_hours'  => $threshold,
                'stale'            => $enabled && ($hours === null || $hours > $threshold),
                'state'            => ! $enabled ? 'off' : ($last === null ? 'never_seen' : ($hours > $threshold ? 'stale' : 'ok')),
            ];
        }

        return $out;
    }

    /**
     * One informational sentence per stale routine, for the daily report.
     *
     * @param array<string, array<string,mixed>> $health
     * @return list<string>
     */
    public static function staleLines(array $health): array
    {
        $lines = [];
        foreach ($health as $type => $h) {
            if (empty($h['stale'])) {
                continue;
            }
            $lines[] = $h['last_activity_at'] === null
                ? sprintf('No %s routine activity has ever reached Reach (%s) — check that the routine exists in claude.ai → Routines.', str_replace('_', ' ', $type), $h['routine'])
                : sprintf('No %s routine activity for %s h (%s, last %s; expected at least every %d h).', str_replace('_', ' ', $type),
                    (string) $h['hours_since'], $h['routine'], (string) $h['last_activity_at'], (int) $h['threshold_hours']);
        }

        return $lines;
    }

    /** @return array<string, array{last_at:string, endpoint:string}> */
    private function apiActivity(): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_routine_activity')) {
            return [];
        }
        $out = [];
        foreach ($this->db->query(
            'SELECT DISTINCT ON (content_type) content_type, endpoint, last_at FROM reach_routine_activity ORDER BY content_type, last_at DESC'
        )->getResultArray() as $r) {
            $out[(string) $r['content_type']] = ['last_at' => (string) $r['last_at'], 'endpoint' => (string) $r['endpoint']];
        }

        return $out;
    }

    /** @return array<string, ?string> */
    private function lastSubmissions(): array
    {
        $q = function (string $table, string $sql, array $binds = []): ?string {
            if (! SchemaGuard::hasTable($this->db, $table)) {
                return null;
            }
            try {
                $v = $this->db->query($sql, $binds)->getRowArray()['at'] ?? null;

                return $v !== null ? (string) $v : null;
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'blog'            => $q('reach_routine_submissions', "SELECT MAX(created_at) AS at FROM reach_routine_submissions WHERE content_type = 'blog'"),
            'knowledge_base'  => $q('reach_routine_submissions', "SELECT MAX(created_at) AS at FROM reach_routine_submissions WHERE content_type = 'knowledge_base'"),
            'community'       => $q('reach_community_automation_submissions', 'SELECT MAX(created_at) AS at FROM reach_community_automation_submissions'),
            'content_base'    => $q('reach_content_base_entries', "SELECT MAX(created_at) AS at FROM reach_content_base_entries WHERE origin = 'routine'"),
            'release_note'    => $q('reach_structured_content_submissions', "SELECT MAX(created_at) AS at FROM reach_structured_content_submissions WHERE content_type = 'release_note'"),
            'workflow_guide'  => $q('reach_structured_content_submissions', "SELECT MAX(created_at) AS at FROM reach_structured_content_submissions WHERE content_type = 'workflow_guide'"),
            'video'           => $q('reach_video_pipeline_items', 'SELECT MAX(created_at) AS at FROM reach_video_pipeline_items'),
            'growth_strategy' => $q('reach_strategy_memos', 'SELECT MAX(created_at) AS at FROM reach_strategy_memos'),
        ];
    }

    private static function enabled(string $type): bool
    {
        $key = match ($type) {
            'blog'           => 'automation.blog.enabled',
            'knowledge_base' => 'automation.knowledge_base.enabled',
            'community'      => 'automation.community.enabled',
            'release_note'   => 'automation.release_note.enabled',
            'workflow_guide' => 'automation.workflow_guide.enabled',
            'video'          => 'automation.video.enabled',
            default          => null,
        };

        return $key === null || (bool) ReachConfig::get($key, true);
    }

    private static function latest(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strtotime($a) >= strtotime($b) ? $a : $b;
    }
}
