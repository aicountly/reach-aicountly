<?php

declare(strict_types=1);

namespace App\Libraries\Content\Quality;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\AuditLogger;
use App\Libraries\Automation\Routine\RoutineSubmissions;
use App\Libraries\Automation\Routine\RoutineVetting;
use App\Libraries\Blog\BlogContentApprovalService;
use App\Libraries\Community\Automation\CommunityAnswerRepairService;
use App\Libraries\Content\ContentQualityHeuristics;
use App\Libraries\Publishing\Jobs\PublicationRollbackService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Re-vets stored content — approved-but-unpublished and live — and clears out
 * what does not belong on aicountly.com (reach:content-revet, and the daily
 * quality sweep on newly published items).
 *
 * Detection: the cheap heuristics first (ContentQualityHeuristics), then the
 * vetting model on the CURRENT version with the type's rubric (which rejects
 * title ↔ body mismatches, filler and generation meta-text); a revise is
 * re-vetted once, as at intake.
 *
 * What happens (unless dry-run):
 *
 *   unpublished, passes  → blog/KB adopted into the routine pipeline
 *                          (origin 'revet', no quota): cover, publish, verify.
 *   unpublished, fails   → approval revoked, item rejected; blog/KB queued
 *                          for regeneration on the same slug.
 *   live, Gemini REJECT  → unpublished through the publisher (status
 *                          `withdrawn` on aicountly.com: reversible, the slug
 *                          stays reserved in the receiver's registry and in
 *                          Reach), then blog/KB queued for regeneration and
 *                          republished at the same URL by the routine.
 *   live, revise→approve → the corrected text Gemini approved is applied by
 *                          LiveRevisionApplier: a new current version,
 *                          approved under the SystemApprover, republished
 *                          through the publisher at the SAME slug and public
 *                          record (`revision_applied+republish_queued`). A
 *                          title that drifts from the topic is not taken; the
 *                          slug never changes; nothing is taken down. An error
 *                          rolls back and reads `revision_failed` (reason in
 *                          the reasons and the log).
 *   live, other revise   → listed for the owner (`flagged_for_owner`): an
 *                          unresolved revise, no changed text, a HIGH_RISK
 *                          verdict, a release note / workflow guide.
 *   live, pass           → recorded only.
 *   vetting unavailable  → nothing changes; listed as unavailable.
 *
 * Live community answers (--community, and the daily sweep), through
 * CommunityAnswerRepairService and the community automation chain:
 *
 *   Gemini REJECT        → unpublished on aicountly.com and marked withdrawn in
 *                          Reach (reversible), then queued for the
 *                          reach-community-qa routine as a priority re-answer
 *                          (no quota); the new answer is a correction of the
 *                          same answer, republished at the same URL.
 *   revise with text     → the revised answer is published as a correction
 *                          version (vet → SystemApprover → publish → verify);
 *                          the current text stays live until then.
 *   revise without text  → left live and queued for a re-answer.
 *   already being fixed  → not vetted again (`none`, with the reason).
 *
 * A revise never takes an answer down; risk tier 4 is never re-published.
 * Every row of a dry run carries `would` — the step a real run takes.
 *
 * Heuristic flags alone never retire or unpublish anything.
 */
class ContentRevetService
{
    public const ITEM_TYPES = ['blog', 'knowledge_base', 'release_note', 'workflow_guide'];

    private BaseConnection $db;
    private QualityCheckLog $log;

    /** @var (callable(int $deploymentId, string $reason): bool)|null */
    private $unpublisher;

    private ?LiveRevisionApplier $revisions;
    /** What the live-revision branch did, for the report row and the check log. */
    private ?string $revisionNote = null;
    private ?CommunityAnswerRepairService $communityRepair;

    public function __construct(
        private readonly ?ContentVettingService $vetting = null,
        ?BaseConnection $db = null,
        ?callable $unpublisher = null,
        ?LiveRevisionApplier $revisions = null,
        ?CommunityAnswerRepairService $communityRepair = null,
    ) {
        $this->db              = $db ?? Database::connect();
        $this->log             = new QualityCheckLog($this->db);
        $this->unpublisher     = $unpublisher;
        $this->revisions       = $revisions;
        $this->communityRepair = $communityRepair;
    }

    /**
     * @param array{types?: list<string>, scope?: string, dry_run?: bool, heuristics_only?: bool,
     *              since?: ?string, ids?: list<int>, limit?: int, trigger?: string, community?: bool,
     *              answer_ids?: list<int>} $options
     * @return array{items: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function run(array $options = []): array
    {
        $types   = array_values(array_intersect($options['types'] ?? self::ITEM_TYPES, self::ITEM_TYPES));
        $scope   = in_array($options['scope'] ?? 'all', ['unpublished', 'live', 'all'], true) ? ($options['scope'] ?? 'all') : 'all';
        $dryRun  = (bool) ($options['dry_run'] ?? false);
        $trigger = (string) ($options['trigger'] ?? 'command');
        $limit   = max(1, (int) ($options['limit'] ?? 200));

        $rows = [];
        foreach ($this->candidates($types, $scope, $options['since'] ?? null, $options['ids'] ?? [], $limit) as $candidate) {
            $rows[] = $this->checkItem($candidate, $dryRun, (bool) ($options['heuristics_only'] ?? false), $trigger);
        }
        if (! empty($options['community'])) {
            foreach ($this->communityAnswers($limit, $options['since'] ?? null, $options['answer_ids'] ?? []) as $answer) {
                $rows[] = $this->checkCommunityAnswer($answer, $dryRun, (bool) ($options['heuristics_only'] ?? false), $trigger);
            }
        }

        $totals = ['checked' => count($rows)];
        foreach ($rows as $r) {
            $totals[$r['decision']] = ($totals[$r['decision']] ?? 0) + 1;
            if ($r['action'] !== 'none') {
                $totals['action:' . $r['action']] = ($totals['action:' . $r['action']] ?? 0) + 1;
            }
            if ($dryRun && ($r['would'] ?? 'none') !== 'none') {
                $totals['would:' . $r['would']] = ($totals['would:' . $r['would']] ?? 0) + 1;
            }
        }

        return ['items' => $rows, 'totals' => $totals];
    }

    /**
     * Items to check: approved but not live, and live (a published/verified
     * deployment, or a live workflow state).
     *
     * @param list<string> $types
     * @param list<int>    $ids
     * @return list<array{id: int, type: string, title: string, slug: string, scope: string, deployment_id: ?int}>
     */
    public function candidates(array $types, string $scope, ?string $since, array $ids, int $limit): array
    {
        if ($types === []) {
            return [];
        }
        $builder = $this->db->table('reach_content_items i')
            ->select("i.id, i.content_type, i.title, i.slug, i.workflow_status, i.approval_status,
                      (SELECT d.id FROM reach_publication_deployments d WHERE d.content_item_id = i.id
                         AND d.status IN ('published','verified','rolled_back') AND d.public_content_id IS NOT NULL
                       ORDER BY d.id DESC LIMIT 1) AS live_deployment_id,
                      (SELECT MAX(COALESCE(d.completed_at, d.updated_at)) FROM reach_publication_deployments d WHERE d.content_item_id = i.id
                         AND d.status IN ('published','verified','rolled_back')) AS live_since", false)
            ->whereIn('i.content_type', $types)
            ->where('i.deleted_at IS NULL', null, false)
            ->orderBy('i.id', 'ASC');
        if ($ids !== []) {
            $builder->whereIn('i.id', array_map('intval', $ids));
        }

        $out = [];
        foreach ($builder->get()->getResultArray() as $r) {
            $live = $r['live_deployment_id'] !== null || in_array($r['workflow_status'], ['live', 'published', 'verification_pending'], true);
            if ($live && in_array($r['workflow_status'], ['unpublished', 'archived'], true) && $r['live_deployment_id'] === null) {
                $live = false;
            }
            $itemScope = $live ? 'live' : 'unpublished';
            if (! $live && ($r['approval_status'] !== 'approved' || in_array($r['workflow_status'], ['rejected', 'archived', 'unpublished'], true))) {
                continue; // only approved-but-unpublished work is "pending publication"
            }
            if ($scope !== 'all' && $scope !== $itemScope) {
                continue;
            }
            if ($since !== null && $live && ($r['live_since'] === null || (strtotime((string) $r['live_since']) ?: 0) < (strtotime($since) ?: 0))) {
                continue;
            }
            $out[] = [
                'id' => (int) $r['id'], 'type' => (string) $r['content_type'], 'title' => (string) $r['title'],
                'slug' => (string) $r['slug'], 'scope' => $itemScope,
                'deployment_id' => $r['live_deployment_id'] !== null ? (int) $r['live_deployment_id'] : null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array{id: int, type: string, title: string, slug: string, scope: string, deployment_id: ?int} $c
     * @return array<string, mixed>
     */
    private function checkItem(array $c, bool $dryRun, bool $heuristicsOnly, string $trigger): array
    {
        $row = ['id' => $c['id'], 'type' => $c['type'], 'title' => $c['title'], 'slug' => $c['slug'], 'scope' => $c['scope'],
                'flags' => [], 'decision' => 'skipped', 'vetting' => null, 'reasons' => [], 'action' => 'none'];

        $content = (new VettableContent($this->db))->forItem($c['id']);
        if ($content === null) {
            $row['reasons'] = ['No current version to vet.'];

            return $row;
        }
        $h = ContentQualityHeuristics::assess($content['title'], $content['summary'], $content['body']);
        $row['flags'] = $h['flags'];
        $base = ['subject_type' => $c['type'], 'subject_id' => $c['id'], 'content_version_id' => $content['version_id'],
                 'scope' => $c['scope'], 'trigger_source' => $trigger, 'heuristic_flags' => $h['flags'], 'dry_run' => $dryRun];

        if ($heuristicsOnly) {
            $row['reasons'] = $h['flags'] === [] ? ['Heuristics found nothing; not vetted (heuristics-only run).'] : ['Heuristics only — vet to decide.'];

            return $row;
        }
        if ($trigger === 'daily_sweep' && $this->log->alreadyChecked($c['type'], $c['id'], $content['version_id'], $trigger)) {
            $row['reasons'] = ['This version was already checked by the daily sweep.'];

            return $row;
        }

        try {
            $verdict = (new RoutineVetting($this->vetting ?? new ContentVettingService()))->run(
                $content['rubric'],
                $content['draft'],
                (new VettableContent($this->db))->context($c['type'], $h['flags']),
                "{$c['type']}:{$c['id']}:v{$content['version_id']}:revet",
            );
        } catch (VettingUnavailableException $e) {
            $row['decision'] = 'unavailable';
            $row['reasons']  = [$e->getMessage()];
            $this->log->record($base + ['decision' => 'unavailable', 'reasons' => $row['reasons']]);

            return $row;
        }

        $row['vetting'] = $verdict['decision'];
        $row['reasons'] = array_slice($verdict['reasons'], 0, 6);
        $rejected       = $verdict['decision'] === 'rejected';
        $row['decision'] = $verdict['approved'] ? ($verdict['decision'] === 'approved' ? 'pass' : 'revise')
            : ($rejected ? 'fail' : 'revise');
        if ($c['scope'] === 'unpublished' && ! $verdict['approved']) {
            // Not publishable as it stands (the intake rule: a second revise retires it).
            $row['decision'] = 'fail';
        }

        $checkId = null;
        $this->revisionNote = null;
        if (! $dryRun) {
            $row['action'] = $this->act($c, $row['decision'], $rejected, $verdict['reasons'], $verdict, $content);
        } else {
            $row['action'] = 'none';
            $row['would']  = $this->wouldDo($c, $row['decision'], $rejected, $verdict, $content);
        }
        if ($this->revisionNote !== null) {
            $row['reasons'][] = $this->revisionNote;
        }
        $checkId = $this->log->record($base + [
            'decision' => $row['decision'], 'vetting_decision' => $verdict['decision'], 'vetting_run_id' => $verdict['run_id'],
            'reasons' => $this->revisionNote !== null ? [...$verdict['reasons'], $this->revisionNote] : $verdict['reasons'], 'action' => $row['action'],
        ]);
        if ($checkId !== null && str_contains($row['action'], 'regeneration')) {
            $this->db->table('reach_content_regenerations')->where('content_item_id', $c['id'])
                ->whereIn('status', RegenerationQueue::OPEN)->where('quality_check_id IS NULL', null, false)
                ->update(['quality_check_id' => $checkId]);
        }

        return $row;
    }

    /**
     * @param list<string>         $reasons
     * @param array<string, mixed> $verdict RoutineVetting::run()
     * @param array<string, mixed> $content VettableContent::forItem()
     */
    private function act(array $c, string $decision, bool $rejected, array $reasons, array $verdict = [], array $content = []): string
    {
        $regenerable = in_array($c['type'], ['blog', 'knowledge_base'], true);

        if ($c['scope'] === 'unpublished') {
            if ($decision === 'fail') {
                $this->retire($c, $reasons, false);

                return $regenerable && (new RegenerationQueue($this->db))->enqueue($c['id'], $reasons, false) !== null
                    ? 'retired+queued_regeneration' : 'retired';
            }
            if ($regenerable) {
                return $this->adopt($c);
            }

            return 'none';
        }

        // Live: only a vetting-model REJECT takes a page down; an approved
        // correction is applied and republished at the same URL.
        if (! $rejected) {
            if ($decision === 'pass') {
                return 'none';
            }
            $result = $this->revisions()->apply($c, $content, $verdict);
            $this->revisionNote = $result['note'];

            return $result['action'];
        }
        if ($c['deployment_id'] === null) {
            return 'flagged_for_owner'; // live by workflow only; nothing the publisher can withdraw
        }
        $ok = $this->unpublish($c['deployment_id'], 'Content quality clean-up: the vetting model rejected the live text. ' . implode(' ', array_slice($reasons, 0, 2)));
        if (! $ok) {
            return 'unpublish_failed';
        }
        $this->retire($c, $reasons, true);

        return $regenerable && (new RegenerationQueue($this->db))->enqueue($c['id'], $reasons, true) !== null
            ? 'unpublished+queued_regeneration' : 'unpublished';
    }

    private function wouldDo(array $c, string $decision, bool $rejected, array $verdict = [], array $content = []): string
    {
        $regenerable = in_array($c['type'], ['blog', 'knowledge_base'], true);
        if ($c['scope'] === 'unpublished') {
            return $decision === 'fail' ? ($regenerable ? 'retire+queue_regeneration' : 'retire') : ($regenerable ? 'adopt_into_pipeline' : 'none');
        }

        if (! $rejected && $decision !== 'pass') {
            $plan = $this->revisions()->plan($c, $content, $verdict);
            $this->revisionNote = $plan['note'];

            return $plan['applicable'] ? 'apply_revision+republish' : 'flag_for_owner';
        }

        return $rejected ? ($regenerable ? 'unpublish+queue_regeneration' : 'unpublish') : 'none';
    }

    private function revisions(): LiveRevisionApplier
    {
        return $this->revisions ??= new LiveRevisionApplier($this->db);
    }

    private function unpublish(int $deploymentId, string $reason): bool
    {
        try {
            return $this->unpublisher !== null
                ? (bool) ($this->unpublisher)($deploymentId, $reason)
                : (new PublicationRollbackService())->unpublish($deploymentId, mb_substr($reason, 0, 500));
        } catch (\Throwable $e) {
            log_message('error', "[ContentRevet] unpublish of deployment {$deploymentId} failed: " . $e->getMessage());

            return false;
        }
    }

    /**
     * Take the approval away and mark the item: rejected when it never went
     * out, unpublished when it was live. The slug is left as it is.
     *
     * @param list<string> $reasons
     */
    private function retire(array $c, array $reasons, bool $wasLive): void
    {
        $now    = date('Y-m-d H:i:s');
        $reason = mb_substr('Failed content re-vetting: ' . implode(' ', array_slice($reasons, 0, 3)), 0, 900);
        if ($c['type'] === 'blog') {
            try {
                (new BlogContentApprovalService())->revoke($c['id'], $reason);
            } catch (\Throwable) {
            }
        }
        $status = $wasLive ? 'unpublished' : 'rejected';
        $this->db->table('reach_content_items')->where('id', $c['id'])->update([
            'workflow_status' => $status, 'approval_status' => 'rejected', 'updated_at' => $now,
        ]);
        if ($c['type'] === 'blog') {
            $this->db->table('reach_content_blog_details')->where('content_item_id', $c['id'])->update(['automation_state' => $status, 'updated_at' => $now]);
        }
        // Any routine submission still carrying it forward stops here.
        $this->db->table('reach_routine_submissions')->where('content_item_id', $c['id'])
            ->whereIn('status', RoutineSubmissions::OPEN)
            ->update(['status' => RoutineSubmissions::REJECTED, 'last_error' => $reason, 'updated_at' => $now]);

        AuditLogger::record($wasLive ? 'content.quality_unpublished' : 'content.quality_retired', [
            'content_item_id' => $c['id'], 'content_type' => $c['type'], 'slug' => $c['slug'], 'reasons' => array_slice($reasons, 0, 6),
        ]);
    }

    /**
     * Hand an approved, re-vetted blog/KB item to the routine pipeline so it is
     * covered, published and verified like a routine article (it vets the
     * version again at intake and approves under the SystemApprover).
     */
    private function adopt(array $c): string
    {
        $subs     = new RoutineSubmissions($this->db);
        $existing = $subs->findByItem($c['id']);
        if ($existing !== null && in_array($existing['status'], RoutineSubmissions::OPEN, true)) {
            return 'already_in_pipeline';
        }
        if ($existing !== null && $existing['status'] === RoutineSubmissions::HELD_HIGH_RISK) {
            return 'held_high_risk_left_as_is';
        }

        $now = date('Y-m-d H:i:s');
        if ($c['type'] === 'blog') {
            // Out of the old publish limbo states the pipeline cannot walk from.
            $state = (string) ($this->db->table('reach_content_items')->select('workflow_status')->where('id', $c['id'])->get()->getRowArray()['workflow_status'] ?? '');
            if (in_array($state, ['publish_queued', 'publishing', 'blocked', 'failed', 'scheduled'], true)) {
                $this->db->table('reach_content_items')->where('id', $c['id'])->update(['workflow_status' => 'approved', 'updated_at' => $now]);
            }
        }

        if ($existing !== null) {
            $this->db->table('reach_routine_submissions')->where('id', $existing['id'])->update([
                'status' => RoutineSubmissions::PENDING_VETTING, 'origin' => 'revet', 'publish_attempts' => 0,
                'vetting_attempts' => 0, 'next_attempt_at' => null, 'deployment_id' => null, 'last_error' => null, 'updated_at' => $now,
            ]);
        } else {
            $id = $subs->create($c['type'], $c['id'], null, ['adopted_by' => 'reach:content-revet']);
            $this->db->table('reach_routine_submissions')->where('id', $id)->update(['origin' => 'revet']);
        }
        AuditLogger::record('content.quality_adopted', ['content_item_id' => $c['id'], 'content_type' => $c['type']]);

        return 'adopted_into_pipeline';
    }

    // ── community answers ───────────────────────────────────────────────────

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function communityAnswers(int $limit, ?string $since, array $ids = []): array
    {
        try {
            $b = $this->db->table('reach_community_official_answers a')
                ->select('a.id, a.uuid, a.status, a.current_version, a.public_url, a.updated_at, q.title AS question_title, q.body AS question_body, v.content, v.excerpt')
                ->join('reach_community_questions q', 'q.id = a.question_id')
                ->join('reach_community_answer_versions v', 'v.answer_id = a.id AND v.version_number = a.current_version', 'left')
                ->where('a.publication_status', 'published')
                ->orderBy('a.id', 'ASC')->limit($limit);
            if ($since !== null) {
                $b->where('a.updated_at >=', $since);
            }
            if ($ids !== []) {
                $b->whereIn('a.id', array_map('intval', $ids));
            }

            return $b->get()->getResultArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function repair(): CommunityAnswerRepairService
    {
        return $this->communityRepair ??= new CommunityAnswerRepairService($this->db, $this->vetting);
    }

    /** @param array<string, mixed> $a */
    private function checkCommunityAnswer(array $a, bool $dryRun, bool $heuristicsOnly, string $trigger): array
    {
        $title = (string) $a['question_title'];
        $row   = ['id' => (int) $a['id'], 'type' => 'community_answer', 'title' => $title, 'slug' => (string) ($a['public_url'] ?? ''),
                  'scope' => 'live', 'flags' => [], 'decision' => 'skipped', 'vetting' => null, 'reasons' => [], 'action' => 'none'];
        if ($dryRun) {
            $row['would'] = 'none';
        }
        $versionNo = (int) ($a['current_version'] ?? 0);
        if ($trigger === 'daily_sweep' && $this->log->alreadyChecked('community_answer', (int) $a['id'], $versionNo, $trigger)) {
            $row['reasons'] = ['This version was already checked by the daily sweep.'];

            return $row;
        }
        $h = ContentQualityHeuristics::assess($title, (string) ($a['excerpt'] ?? ''), (string) ($a['content'] ?? ''));
        // Short answers are normal on the community desk.
        $row['flags'] = array_values(array_filter($h['flags'], static fn (string $f) => ! str_starts_with($f, 'short_body') && $f !== 'title_not_reflected_in_body'));
        if ($heuristicsOnly) {
            return $row;
        }
        // Already being fixed (a re-answer queued, a correction in flight):
        // the current_version may not even be the live text. Not vetted again.
        $busy = $this->repair()->inProgress((int) $a['id']);
        if ($busy !== null) {
            $row['reasons'] = [$busy];

            return $row;
        }
        try {
            $result = ($this->vetting ?? new ContentVettingService())->vet('community', [
                'question_title' => $title, 'question_body' => (string) ($a['question_body'] ?? ''),
                'answer_html' => (string) ($a['content'] ?? ''), 'short_answer' => (string) ($a['excerpt'] ?? ''),
            ], ['review' => 'This answer is already live. Judge the current text: does it answer the question, accurately?'], 'community_answer:' . $a['id'] . ':revet');
        } catch (VettingUnavailableException $e) {
            $row['decision'] = 'unavailable';
            $row['reasons']  = [$e->getMessage()];

            return $row;
        }
        $row['vetting']  = $result->decision;
        $row['reasons']  = array_slice($result->reasons, 0, 6);
        $row['decision'] = $result->decision === VettingResult::APPROVE ? 'pass' : ($result->decision === VettingResult::REJECT ? 'fail' : 'revise');

        $revised = CommunityAnswerRepairService::revisedText($result, (string) ($a['content'] ?? ''), (string) ($a['excerpt'] ?? ''));
        $outcome = ['action' => 'none', 'reanswer_id' => null, 'error' => null];
        if ($dryRun) {
            $row['would'] = $this->repair()->plan((int) $a['id'], $result->decision, $revised);
        } elseif ($row['decision'] !== 'pass') {
            $outcome       = $this->repair()->apply((int) $a['id'], $result->decision, $revised, $result->reasons);
            $row['action'] = $outcome['action'];
            if ($outcome['error'] !== null) {
                $row['reasons'][] = $outcome['error'];
            }
        }
        $checkId = $this->log->record([
            'subject_type' => 'community_answer', 'subject_id' => (int) $a['id'], 'content_version_id' => $versionNo ?: null,
            'scope' => 'live', 'trigger_source' => $trigger,
            'heuristic_flags' => $row['flags'], 'decision' => $row['decision'], 'vetting_decision' => $result->decision,
            'vetting_run_id' => $result->runId, 'reasons' => array_merge($result->reasons, $outcome['error'] !== null ? [$outcome['error']] : []),
            'action' => mb_substr($row['action'], 0, 48), 'dry_run' => $dryRun,
        ]);
        if ($outcome['reanswer_id'] !== null) {
            $this->repair()->queue()->setQualityCheck((int) $outcome['reanswer_id'], $checkId);
        }

        return $row;
    }
}
