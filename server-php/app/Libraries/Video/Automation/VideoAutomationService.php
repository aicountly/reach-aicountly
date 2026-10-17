<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\Ai\Vetting\VettingResult;
use App\Libraries\Ai\Vetting\VettingUnavailableException;
use App\Libraries\AuditLogger;
use App\Libraries\Automation\SystemApprover;
use App\Libraries\SaasProductTaxonomy;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Render\ShotstackClient;
use App\Libraries\Video\Automation\YouTube\YouTubeOAuth;

/**
 * Intake for the reach-video routine: a script arrives, is validated, vetted
 * by Gemini (the decision is final), approved under the Reach System Bot and
 * handed to the pipeline. Nothing here generates text with an LLM.
 *
 *   approve           → approved by SystemApprover, pipeline starts (tts)
 *   revise            → corrections applied to text fields, re-vetted once;
 *                       only an `approve` on the re-vet goes ahead
 *   reject            → stored as rejected with the reasons, never rendered
 *   vetting down      → stays pending_vetting; the hourly sweep re-vets
 */
class VideoAutomationService
{
    public const CONTENT_TYPE = 'video_script';

    public function __construct(
        private readonly VideoPipelineRepository $repo = new VideoPipelineRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws \InvalidArgumentException  invalid script (422)
     * @throws VideoQuotaException        switched off or today's quota used (429)
     */
    public function ingest(array $payload): array
    {
        if (! (bool) ReachConfig::get('automation.video.enabled')) {
            throw new VideoQuotaException('Video automation is switched off (Settings → AI & Automation → Video). Stop for today.');
        }
        $script = VideoScriptValidator::normalize($payload);

        $existing = $this->repo->findByExternalKey($script['external_key']);
        if ($existing !== null) {
            return ['status' => 'already_received'] + $this->summary($existing);
        }

        $limit = $this->dailyLimit($script['format']);
        $used  = $this->repo->countToday($script['format']);
        if ($used >= $limit) {
            throw new VideoQuotaException(sprintf("Today's %s quota is used (%d of %d). Stop for today.", $script['format'], $used, $limit));
        }
        // A routine that keeps sending scripts the vetting model rejects must stop too.
        if ($this->repo->countToday($script['format'], true) >= max(3, $limit * 3)) {
            throw new VideoQuotaException("Too many {$script['format']} scripts were submitted today. Stop for today.");
        }

        $id = $this->repo->create($script, $payload);
        AuditLogger::record('video.script.received', ['item_id' => $id, 'external_key' => $script['external_key'], 'format' => $script['format']], null);

        return $this->vet($id);
    }

    /**
     * Vet (or re-vet) a pending item and act on the decision.
     *
     * @return array<string, mixed>
     */
    public function vet(int $itemId): array
    {
        $item = $this->repo->find($itemId);
        if ($item === null) {
            throw new \RuntimeException("Video item {$itemId} not found.");
        }
        if ($item['status'] !== 'pending_vetting') {
            return ['status' => $item['status']] + $this->summary($item);
        }

        $script  = (array) $item['script_json'];
        $context = $this->vettingContext($script);
        $ref     = 'video:' . $item['uuid'];
        $vetting = VideoProviders::vetting();
        $runIds  = [];

        try {
            $first    = $vetting->vet(self::CONTENT_TYPE, VideoScriptValidator::vettingDraft($script), $context, $ref);
            $runIds[] = $first->runId;
            $final    = $first;
            $reasons  = $first->reasons;

            if ($first->decision === VettingResult::REVISE) {
                try {
                    $script = VideoScriptValidator::applyRevision($script, $first->final);
                } catch (\InvalidArgumentException $e) {
                    return $this->reject($item, array_merge($reasons, ['The vetting model’s revision no longer fits the video format: ' . $e->getMessage()]), $first, $runIds);
                }
                $final    = $vetting->vet(self::CONTENT_TYPE, VideoScriptValidator::vettingDraft($script), $context, $ref);
                $runIds[] = $final->runId;
                $reasons  = array_merge($reasons, $final->reasons);
            }
        } catch (VettingUnavailableException $e) {
            $this->repo->update($itemId, ['last_error' => mb_substr($e->getMessage(), 0, 1000), 'step_detail' => 'Waiting for the vetting model; the hourly sweep retries.']);
            $item = (array) $this->repo->find($itemId);
            return ['status' => 'pending_vetting', 'message' => $e->getMessage()] + $this->summary($item);
        }

        if (! $final->approved()) {
            if ($final->decision === VettingResult::REVISE) {
                $reasons[] = 'Still needed changes after one revision, so it was not approved.';
            }
            return $this->reject($item, $reasons, $final, $runIds);
        }

        return $this->approve($item, $script, $final, $reasons, $runIds);
    }

    /**
     * What the routine needs before writing: remaining quota, recent titles
     * (to avoid repeats), topics, the screens it may screenshot, whether the
     * channel is connected.
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $quota = [];
        foreach (['vlog', 'short'] as $format) {
            $limit = $this->dailyLimit($format);
            $used  = $this->repo->countToday($format);
            $quota[$format] = ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used)];
        }
        $channel = (new YouTubeChannelService())->status();

        return [
            'enabled'            => (bool) ReachConfig::get('automation.video.enabled'),
            'date_ist'           => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'),
            'quota'              => $quota,
            'youtube_connected'  => $channel['connected'],
            'youtube_channel'    => $channel['channel_title'],
            'rendering_configured' => ShotstackClient::isConfigured(),
            'voice_configured'   => ReachConfig::secret('ai.openai.api_key') !== null,
            'recent_titles'      => $this->repo->recentTitles(40),
            'recommended_topics' => VideoTopicPlanner::recommend(),
            'screens'            => VideoTopicPlanner::screens(),
            'format_rules'       => [
                'vlog'  => ['aspect' => '16:9', 'length' => '2–6 minutes', 'voice_over_words' => '300–850', 'scenes' => '3–40'],
                'short' => ['aspect' => '9:16', 'length' => '≤ 60 seconds', 'voice_over_words' => '≤ 140', 'scenes' => '1–12'],
                'words_per_second' => VideoScriptValidator::WORDS_PER_SECOND,
            ],
        ];
    }

    public function dailyLimit(string $format): int
    {
        return max(0, (int) ReachConfig::get($format === 'short' ? 'video.daily.shorts' : 'video.daily.vlogs'));
    }

    /** @return array<string, mixed> */
    public function summary(array $item): array
    {
        return [
            'item_uuid'        => $item['uuid'],
            'external_key'     => $item['external_key'],
            'format'           => $item['format'],
            'title'            => $item['title'],
            'pipeline_status'  => $item['status'],
            'step'             => $item['step'],
            'vetting_decision' => $item['vetting_decision'],
            'vetting_reasons'  => $item['vetting_reasons_json'],
            'youtube_url'      => $item['youtube_url'],
        ];
    }

    /**
     * @param array<string, mixed> $script
     * @return array<string, mixed>
     */
    private function vettingContext(array $script): array
    {
        $slug    = (string) ($script['product_slug'] ?? '');
        $context = [
            'format'          => $script['format'],
            'published_on'    => 'YouTube (public) — Shorts are also offered to Instagram Reels, Facebook and LinkedIn',
            'voice'           => 'synthetic voice-over (OpenAI text-to-speech); captions burned in from the script',
            'company'         => 'AICOUNTLY — Indian cloud accounting and business software suite (aicountly.com)',
            'product_screens' => VideoTopicPlanner::screens(),
        ];
        if ($slug !== '') {
            $context['product'] = ['slug' => $slug, 'name' => SaasProductTaxonomy::labelProduct(SaasProductTaxonomy::normalizeProductSlug($slug))];
        }
        return $context;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $script
     * @param list<string>         $reasons
     * @param list<int|null>       $runIds
     * @return array<string, mixed>
     */
    private function approve(array $item, array $script, VettingResult $result, array $reasons, array $runIds): array
    {
        $botId = SystemApprover::userId();
        $db    = $this->repo->db();
        $now   = gmdate('Y-m-d H:i:s');

        $db->transException(true)->transStart();
        try {
            $db->table('reach_approvals')->insert([
                'subject_type' => 'video_script',
                'subject_id'   => (int) $item['id'],
                'summary'      => 'Video script approved automatically after vetting: ' . $script['title'],
                'requested_by' => null,
                'decision'     => 'approved',
                'decided_by'   => $botId,
                'decided_at'   => $now,
                'note'         => 'Approved by Reach System Bot on the vetting model’s decision (' . $result->model . '). No person reviewed it.',
                'metadata'     => json_encode([
                    'vetting_run_id'  => $result->runId,
                    'vetting_run_ids' => $runIds,
                    'model'           => $result->model,
                    'item_uuid'       => $item['uuid'],
                    'external_key'    => $item['external_key'],
                ], JSON_UNESCAPED_SLASHES),
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $approvalId = (int) $db->insertID();

            $this->repo->update((int) $item['id'], [
                'script_json'          => $script,
                'title'                => $script['title'],
                'status'               => 'in_progress',
                'step'                 => 'tts',
                'step_detail'          => 'Approved; generating voice-overs.',
                'last_error'           => null,
                'vetting_decision'     => VettingResult::APPROVE,
                'vetting_model'        => $result->model,
                'vetting_reasons_json' => array_values($reasons),
                'vetting_run_ids_json' => array_values(array_filter($runIds, static fn ($v) => $v !== null)),
                'approved_by_user_id'  => $botId,
                'approval_id'          => $approvalId,
                'approved_at'          => $now,
            ]);
            $db->transComplete();
        } finally {
            $db->transException(false);
        }

        AuditLogger::record('video.script.approved', ['item_id' => (int) $item['id'], 'approval_id' => $approvalId, 'vetting_run_id' => $result->runId, 'approver' => 'system-bot'], $botId);
        VideoPipelineRunner::enqueue((int) $item['id'], 'tts');

        return ['status' => 'approved'] + $this->summary((array) $this->repo->find((int) $item['id']));
    }

    /**
     * @param array<string, mixed> $item
     * @param list<string>         $reasons
     * @param list<int|null>       $runIds
     * @return array<string, mixed>
     */
    private function reject(array $item, array $reasons, VettingResult $result, array $runIds): array
    {
        $this->repo->update((int) $item['id'], [
            'status'               => 'rejected',
            'step_detail'          => 'Rejected by the vetting model; never rendered.',
            'vetting_decision'     => $result->decision,
            'vetting_model'        => $result->model,
            'vetting_reasons_json' => array_values($reasons),
            'vetting_run_ids_json' => array_values(array_filter($runIds, static fn ($v) => $v !== null)),
            'last_error'           => null,
        ]);
        AuditLogger::record('video.script.rejected', ['item_id' => (int) $item['id'], 'reasons' => $reasons], null);

        return ['status' => 'rejected'] + $this->summary((array) $this->repo->find((int) $item['id']));
    }
}
