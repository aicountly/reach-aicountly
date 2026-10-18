<?php

declare(strict_types=1);

namespace App\Libraries\Blog;

use App\Libraries\Ai\Vetting\ContentVettingService;
use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\AuditLogger;
use App\Libraries\Content\ContentQualityHeuristics;
use App\Libraries\Content\Quality\QualityCheckLog;
use App\Libraries\Content\Quality\VettableContent;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * No blog version reaches aicountly.com without the vetting model (Gemini,
 * blog rubric — which includes title ↔ body relevance) having approved THAT
 * version. Every publish path goes through PublicationDeploymentService, which
 * calls ensure() for blog publish/schedule deployments: "Publish now", a
 * scheduled publish, the routine pipeline, BlogAutoPublishService /
 * PUBLISH_BLOG, the republish command.
 *
 * The record of a pass is the active, checksum-matching approval row of the
 * version carrying a vetting_run_id whose decision is `approve` — the routine
 * pipeline writes exactly that at intake, so routine articles pass without a
 * second Gemini call. Anything else (a human approval, the retired in-Reach
 * generator's auto-approval) is vetted on the spot; approve stamps the run on
 * the approval, revise/reject refuse the publish with Gemini's reasons, and an
 * outage refuses it as `unavailable` (retry later) — never a silent pass.
 */
class BlogPublishVetGate
{
    private BaseConnection $db;

    public function __construct(private readonly ?ContentVettingService $vetting = null, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{vetted: bool, run_id: ?int, reason: string, approval_id: ?int}
     */
    public function status(int $itemId, int $versionId): array
    {
        $approval = $this->activeApproval($itemId, $versionId);
        if ($approval === null) {
            return ['vetted' => false, 'run_id' => null, 'reason' => 'no_active_approval_for_version', 'approval_id' => null];
        }
        $approvals = new BlogContentApprovalService();
        if (! hash_equals((string) $approval['version_checksum'], $approvals->checksumForVersion($versionId))) {
            return ['vetted' => false, 'run_id' => null, 'reason' => 'approval_checksum_mismatch', 'approval_id' => (int) $approval['id']];
        }
        $runId = (int) ($approval['vetting_run_id'] ?? 0);
        if ($runId <= 0) {
            return ['vetted' => false, 'run_id' => null, 'reason' => 'not_vetted', 'approval_id' => (int) $approval['id']];
        }
        $decision = (string) ($this->db->table('reach_ai_vetting_runs')->select('decision')->where('id', $runId)->get()->getRowArray()['decision'] ?? '');

        return $decision === VettingResult::APPROVE
            ? ['vetted' => true, 'run_id' => $runId, 'reason' => 'vetted', 'approval_id' => (int) $approval['id']]
            : ['vetted' => false, 'run_id' => $runId, 'reason' => 'vetting_run_not_approve:' . ($decision ?: 'missing'), 'approval_id' => (int) $approval['id']];
    }

    /**
     * Pass, or throw BlogVetRefused.
     *
     * @return array{run_id: int, vetted_now: bool}
     */
    public function ensure(int $itemId, int $versionId, string $trigger = 'publish_gate'): array
    {
        $status = $this->status($itemId, $versionId);
        if ($status['vetted']) {
            return ['run_id' => (int) $status['run_id'], 'vetted_now' => false];
        }
        if ($status['approval_id'] === null || $status['reason'] === 'approval_checksum_mismatch') {
            throw new BlogVetRefused('Blog content needs a valid approval of this exact version before publishing (' . $status['reason'] . ').', 'not_approved');
        }

        $content = (new VettableContent($this->db))->forItem($itemId);
        if ($content === null || $content['version_id'] !== $versionId) {
            throw new BlogVetRefused('Only the current version of a blog can be vetted for publication.', 'not_current');
        }

        $heuristics = ContentQualityHeuristics::assess($content['title'], $content['summary'], $content['body']);
        $log        = new QualityCheckLog($this->db);
        $base       = [
            'subject_type' => 'blog', 'subject_id' => $itemId, 'content_version_id' => $versionId,
            'scope' => 'unpublished', 'trigger_source' => $trigger, 'heuristic_flags' => $heuristics['flags'],
        ];

        try {
            $result = ($this->vetting ?? new ContentVettingService())->vet(
                'blog',
                $content['draft'],
                (new VettableContent($this->db))->context('blog', $heuristics['flags']),
                "blog:{$itemId}:v{$versionId}",
            );
        } catch (VettingUnavailableException $e) {
            $log->record($base + ['decision' => 'unavailable', 'reasons' => [$e->getMessage()], 'action' => 'publish_refused']);
            throw new BlogVetRefused('Vetting is unavailable, so this blog is not published unvetted: ' . $e->getMessage(), 'unavailable', [], true);
        }

        if ($result->decision !== VettingResult::APPROVE) {
            $log->record($base + [
                'decision' => $result->decision === VettingResult::REVISE ? 'revise' : 'fail', 'vetting_decision' => $result->decision,
                'vetting_run_id' => $result->runId, 'reasons' => $result->reasons, 'action' => 'publish_refused',
            ]);
            AuditLogger::record('blog.publish_vet_refused', [
                'content_item_id' => $itemId, 'content_version_id' => $versionId, 'decision' => $result->decision,
                'vetting_run_id'  => $result->runId, 'reasons' => array_slice($result->reasons, 0, 10),
            ]);
            $label = $result->decision === VettingResult::REVISE ? 'asks for corrections' : 'rejected it';
            throw new BlogVetRefused(
                "Not published: the vetting model ({$result->model}) {$label}: " . implode(' ', array_slice($result->reasons, 0, 5)),
                $result->decision,
                $result->reasons,
            );
        }

        if ($result->runId !== null) {
            $this->db->table('reach_blog_content_approvals')->where('id', $status['approval_id'])
                ->update(['vetting_run_id' => $result->runId, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        $log->record($base + ['decision' => 'pass', 'vetting_decision' => $result->decision, 'vetting_run_id' => $result->runId, 'reasons' => $result->reasons, 'action' => 'approval_bound']);
        AuditLogger::record('blog.publish_vet_passed', [
            'content_item_id' => $itemId, 'content_version_id' => $versionId, 'vetting_run_id' => $result->runId, 'model' => $result->model,
        ]);

        if ($result->runId === null) {
            // The run could not be recorded, so there is no record to bind:
            // pass this publish, but the next one is vetted again.
            return ['run_id' => 0, 'vetted_now' => true];
        }

        return ['run_id' => $result->runId, 'vetted_now' => true];
    }

    /** @return array<string, mixed>|null */
    private function activeApproval(int $itemId, int $versionId): ?array
    {
        return $this->db->table('reach_blog_content_approvals')
            ->where('content_item_id', $itemId)
            ->where('content_version_id', $versionId)
            ->where('outcome', 'approved')
            ->where('revoked_at IS NULL', null, false)
            ->orderBy('id', 'DESC')->limit(1)
            ->get()->getRowArray() ?: null;
    }
}
