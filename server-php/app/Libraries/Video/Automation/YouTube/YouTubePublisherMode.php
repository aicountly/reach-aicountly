<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\YouTube;

use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\SocialApiAiPublisher;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\YouTubeChannelService;

/**
 * How vlogs and Shorts reach YouTube (`video.youtube.publisher`):
 *
 *   social_api (default)  through social-api.ai, whose YouTube connection is
 *                         already verified by Google (no Reach OAuth app, no
 *                         YouTube API audit, no private-lock)
 *   direct                Reach's own resumable upload with its Google OAuth
 *                         client (the optional fallback)
 *
 * The mode is fixed on each item when its YouTube step first runs, so a
 * settings change never mixes the two paths for one video.
 */
final class YouTubePublisherMode
{
    public const SOCIAL_API = 'social_api';
    public const DIRECT     = 'direct';

    public static function current(): string
    {
        try {
            $v = strtolower(trim((string) ReachConfig::get('video.youtube.publisher')));
        } catch (\Throwable) {
            $v = '';
        }
        return $v === self::DIRECT ? self::DIRECT : self::SOCIAL_API;
    }

    /** The item's own mode once its YouTube step has started, else the setting. */
    public static function forItem(array $item): string
    {
        $v = (string) ($item['youtube_publisher'] ?? '');
        return in_array($v, [self::SOCIAL_API, self::DIRECT], true) ? $v : self::current();
    }

    public static function label(string $mode): string
    {
        return $mode === self::DIRECT ? 'direct upload (Reach’s Google OAuth client)' : 'social-api.ai';
    }

    /**
     * Whether the active path can publish right now (no secrets).
     *
     * @return array{mode: string, label: string, ready: bool, reason: ?string, account: ?array<string, mixed>, channel: ?array<string, mixed>}
     */
    public static function describe(?array $channel = null): array
    {
        $mode = self::current();
        if ($mode === self::DIRECT) {
            $channel ??= (new YouTubeChannelService())->status();
            $ready   = (bool) ($channel['connected'] ?? false);
            return [
                'mode'    => $mode,
                'label'   => self::label($mode),
                'ready'   => $ready,
                'reason'  => $ready ? null : (! ($channel['client_configured'] ?? false)
                    ? 'YouTube OAuth client id/secret are not set (Settings → AI & Automation → Video).'
                    : 'No YouTube channel is connected — Connect YouTube channel on the Video page.'),
                'account' => null,
                'channel' => $channel,
            ];
        }

        try {
            $publisher = ChannelProviderFactory::makeSocialPublisher();
        } catch (\Throwable $e) {
            $publisher = null;
        }
        if (! $publisher instanceof SocialApiAiPublisher) {
            return [
                'mode' => $mode, 'label' => self::label($mode), 'ready' => false,
                'reason' => 'social.socialapi.api_key is not set (Settings → AI & Automation → Social), so videos cannot go to YouTube through social-api.ai. Set the key, or set video.youtube.publisher to direct.',
                'account' => null, 'channel' => null,
            ];
        }
        $r = $publisher->readiness('youtube');

        return [
            'mode'    => $mode,
            'label'   => self::label($mode),
            'ready'   => $r['ok'],
            'reason'  => $r['reason'],
            'account' => $r['account'],
            'channel' => null,
        ];
    }
}
