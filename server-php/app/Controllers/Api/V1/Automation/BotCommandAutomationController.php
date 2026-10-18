<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Automation;

use App\Controllers\Api\V1\BaseApiController;
use App\Controllers\Api\V1\Bot\BotActionResponses;
use App\Libraries\Automation\Social\AutomationSocialPostService;
use App\Libraries\Bot\BotActionException;
use App\Libraries\Bot\BotCommandService;
use App\Libraries\Video\Automation\VideoAutomationService;
use App\Libraries\Video\Automation\VideoPipelineRepository;
use App\Libraries\Video\Automation\VideoSocialDistributor;
use App\Libraries\Bot\SocialChannelReadiness;

/**
 * Endpoints for the reach-command routine (X-Automation-Token):
 *
 *   GET  v1/automation/commands/{id}             the owner command as Reach stored it
 *   POST v1/automation/commands/{id}/report      {status, summary, links?} — updates the command
 *   POST v1/automation/social-posts              a social post → Gemini vet → publish
 *   GET  v1/automation/social-posts/{uuid|key}   its status and the posts it made
 *   GET  v1/automation/videos/{item_uuid}        a pipeline video's status and its social posts
 *   POST v1/automation/videos/{item_uuid}/distribute  {channels?} offer a published video to
 *                                                social networks now (same publisher path)
 */
class BotCommandAutomationController extends BaseApiController
{
    use BotActionResponses;

    public function showCommand(string $id)
    {
        $svc = new BotCommandService();
        $row = $svc->find($id);
        if ($row === null) {
            return $this->notFound('No such command.');
        }
        return $this->ok($svc->forAutomation($row));
    }

    public function report(string $id)
    {
        try {
            $cmd = (new BotCommandService())->report($id, $this->input());
        } catch (BotActionException $e) {
            return $this->refused($e);
        }
        return $this->ok(['stored' => true, 'command_id' => $cmd['uuid'], 'status' => $cmd['status'], 'reported_at' => $cmd['reported_at']]);
    }

    public function storeSocialPost()
    {
        try {
            $result = (new AutomationSocialPostService())->ingest($this->input());
        } catch (BotActionException $e) {
            return $this->refused($e);
        }
        $status = match ($result['status']) {
            'approved'        => 201,
            'pending_vetting' => 202,
            default           => 200,
        };
        return $this->ok($result, $status);
    }

    public function showSocialPost(string $ref)
    {
        $summary = (new AutomationSocialPostService())->findSummary($ref);
        return $summary === null ? $this->notFound('No such social submission.') : $this->ok($summary);
    }

    public function showVideo(string $uuid)
    {
        $repo = new VideoPipelineRepository();
        $item = BotCommandService::isUuid($uuid) ? $repo->findByUuid(strtolower($uuid)) : null;
        if ($item === null) {
            return $this->notFound('No such video item.');
        }
        return $this->ok($this->videoView($item));
    }

    public function distributeVideo(string $uuid)
    {
        $repo = new VideoPipelineRepository();
        $item = BotCommandService::isUuid($uuid) ? $repo->findByUuid(strtolower($uuid)) : null;
        if ($item === null) {
            return $this->notFound('No such video item.');
        }
        if ($item['status'] !== 'published' || empty($item['render_url'])) {
            return $this->fail('This video is ' . $item['status'] . ' (step ' . $item['step'] . '); only a published video can be offered to social networks. Its own social step runs when it is published.', 409, ['reason' => 'not_published', 'pipeline_status' => $item['status'], 'step' => $item['step']]);
        }

        $input    = $this->input();
        $channels = [];
        foreach ((array) ($input['channels'] ?? []) as $c) {
            $n = is_string($c) ? SocialChannelReadiness::normalize($c) : null;
            if ($n === 'youtube') {
                continue;
            }
            if ($n === null || ! in_array($n, VideoSocialDistributor::ALLOWED_CHANNELS, true)) {
                return $this->fail('channels: unknown network ' . json_encode($c) . '. Use: ' . implode(', ', VideoSocialDistributor::ALLOWED_CHANNELS) . '.', 422);
            }
            $channels[] = $n;
        }
        if ($channels === []) {
            // Not given: every network that can really post right now.
            $channels = array_values(array_intersect(VideoSocialDistributor::ALLOWED_CHANNELS, SocialChannelReadiness::readyChannels()));
        }
        if ($channels === []) {
            return $this->fail('No social network is ready (social-api.ai key and accounts): nothing was sent.', 409, ['reason' => 'no_channel_ready']);
        }

        $done = (new VideoSocialDistributor($repo))->distribute($item, $channels);
        \App\Libraries\AuditLogger::record('video.social.distributed', ['item_id' => (int) $item['id'], 'channels' => $channels, 'results' => $done], null);

        return $this->ok(['results' => $done] + $this->videoView((array) $repo->find((int) $item['id'])), 202);
    }

    /** @return array<string, mixed> */
    private function videoView(array $item): array
    {
        $summary = (new VideoAutomationService())->summary($item);
        $ids     = array_values(array_map('intval', (array) ($item['social_post_ids_json'] ?? [])));
        $posts   = [];
        if ($ids !== []) {
            foreach (\Config\Database::connect()->table('reach_social_posts')->select('id, channel, status, remote_url, error_message')->whereIn('id', $ids)->orderBy('id')->get()->getResultArray() as $p) {
                $posts[] = ['post_id' => (int) $p['id'], 'channel' => $p['channel'], 'status' => $p['status'], 'url' => $p['remote_url'], 'error' => $p['error_message']];
            }
        }
        return $summary + [
            'step_detail' => $item['step_detail'] ?? null,
            'last_error'  => $item['last_error'] ?? null,
            'warnings'    => (array) ($item['warnings_json'] ?? []),
            'social_posts' => $posts,
            'note'        => 'pipeline_status `published` = on YouTube; a social post is `posted` only once social-api.ai reports it published.',
        ];
    }
}
