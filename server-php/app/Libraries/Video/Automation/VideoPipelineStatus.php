<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Render\ShotstackClient;
use App\Libraries\Video\Automation\YouTube\YouTubePublisherMode;

/**
 * What the video pipeline can actually do right now. Nothing is reported as
 * ready unless every provider it needs is configured and no mock is in use.
 */
final class VideoPipelineStatus
{
    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $service = new VideoAutomationService();
        $channel = (new YouTubeChannelService())->status();
        $signing = (string) (env('MEDIA_SIGNING_KEY') ?? '') !== '';
        $path    = YouTubePublisherMode::describe($channel);
        $checks  = [
            'automation_enabled' => (bool) ReachConfig::get('automation.video.enabled'),
            'vetting'            => ReachConfig::secret('ai.gemini.api_key') !== null,
            'voice_over'         => ReachConfig::secret('ai.openai.api_key') !== null,
            'rendering'          => ShotstackClient::isConfigured(),
            'media_signing'      => $signing,
            'screenshots'        => \Config\Services::workerClient()->isConfigured(),
        ];
        if ($path['mode'] === YouTubePublisherMode::DIRECT) {
            $checks['youtube_client']  = $channel['client_configured'];
            $checks['youtube_channel'] = $channel['connected'];
        } else {
            $checks['social_api_key']             = ReachConfig::secret('social.socialapi.api_key') !== null;
            $checks['social_api_youtube_account'] = $path['ready'];
        }
        $mocks    = VideoProviders::mocks();
        $required = array_diff_key($checks, ['screenshots' => true]);
        $missing  = array_keys(array_filter($required, static fn ($ok) => ! $ok));

        return [
            'ready'          => ! $mocks && $missing === [],
            'missing'        => $missing,
            'mock_providers' => $mocks,
            'checks'         => $checks,
            'render_provider' => 'shotstack:' . ShotstackClient::environment(),
            'shotstack_env'  => ShotstackClient::environment(),
            'tts'            => ['provider' => 'openai', 'model' => (string) ReachConfig::get('video.tts.model'), 'voice' => (string) ReachConfig::get('video.tts.voice')],
            'daily'          => ['vlog' => $service->dailyLimit('vlog'), 'short' => $service->dailyLimit('short')],
            'youtube'        => $channel,
            // Which path videos take to YouTube (video.youtube.publisher) and whether it can publish now.
            'youtube_path'   => [
                'mode'    => $path['mode'],
                'label'   => $path['label'],
                'ready'   => $path['ready'],
                'reason'  => $path['reason'],
                'account' => $path['account'],
            ],
            'notes'          => array_values(array_filter([
                $mocks ? 'VIDEO_PIPELINE_MOCKS is on: development mocks are in use (they refuse to run in production).' : null,
                ShotstackClient::environment() === 'stage' ? 'Shotstack is on the stage environment: renders carry a watermark. Set video.shotstack.env to v1 for production renders.' : null,
                ! $checks['screenshots'] ? 'Screenshot worker not configured: screenshot scenes fall back to title cards.' : null,
                ! $signing ? 'MEDIA_SIGNING_KEY is not set: the renderer cannot fetch voice-overs, so renders are blocked.' : null,
                'YouTube publishing: ' . $path['label'] . ' (video.youtube.publisher = ' . $path['mode'] . ').' . (! $path['ready'] && $path['reason'] ? ' Not ready: ' . $path['reason'] : ''),
            ])),
        ];
    }
}
