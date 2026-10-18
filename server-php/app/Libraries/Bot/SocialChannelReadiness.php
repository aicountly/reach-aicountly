<?php

declare(strict_types=1);

namespace App\Libraries\Bot;

use App\Libraries\Distribution\Providers\ChannelProviderFactory;
use App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms;
use App\Libraries\Distribution\Providers\SocialApiAiPublisher;

/**
 * Which social networks a post would really go out on right now — the same
 * check the publisher makes (social-api.ai key set and an account for the
 * platform resolves). Used for the channel checkboxes on Bot → Ask the bot,
 * the context sent to the reach-command routine, and to route automation
 * posts to the manual queue instead of pretending they will go out.
 */
final class SocialChannelReadiness
{
    /** Reach channels in display order (reach_social_posts.channel). */
    public const CHANNELS = ['youtube', 'instagram', 'facebook', 'linkedin', 'twitter'];

    /** @var (callable(): list<array<string, mixed>>)|null */
    private static $override = null;

    /** @internal tests: replace the live check */
    public static function override(?callable $fn): void
    {
        self::$override = $fn;
    }

    /**
     * @return list<array{channel: string, platform: ?string, label: string, ready: bool, account: ?string, reason: ?string}>
     */
    public static function all(): array
    {
        if (self::$override !== null) {
            return (self::$override)();
        }

        try {
            $publisher = ChannelProviderFactory::makeSocialPublisher();
        } catch (\Throwable $e) {
            $publisher = null;
            $error     = $e->getMessage();
        }

        $out = [];
        foreach (self::CHANNELS as $channel) {
            $platform = SocialApiPlatforms::forChannel($channel);
            $label    = $platform !== null ? SocialApiPlatforms::label($platform) : ucfirst($channel);
            if ($publisher === null) {
                $out[] = ['channel' => $channel, 'platform' => $platform, 'label' => $label, 'ready' => false, 'account' => null, 'reason' => $error ?? 'No social publisher is configured.'];
                continue;
            }
            if ($publisher instanceof SocialApiAiPublisher) {
                $r       = $publisher->readiness($channel);
                $account = $r['account'] ?? null;
                $out[] = [
                    'channel'  => $channel,
                    'platform' => $platform,
                    'label'    => $label,
                    'ready'    => (bool) ($r['ok'] ?? false),
                    'account'  => is_array($account) ? (string) ($account['name'] ?? $account['username'] ?? $account['id'] ?? '') : null,
                    'reason'   => $r['reason'] ?? null,
                ];
                continue;
            }
            $ready = ChannelProviderFactory::socialPublisherReadyFor($channel);
            $out[] = ['channel' => $channel, 'platform' => $platform, 'label' => $label, 'ready' => $ready, 'account' => null,
                'reason' => $ready ? 'Development mock publisher — nothing is really posted.' : 'No social publisher can post on this network (set the social-api.ai key in Settings → AI & Automation → Social).'];
        }
        return $out;
    }

    /** @return list<string> the channels that would really go out now */
    public static function readyChannels(): array
    {
        return array_values(array_map(static fn ($c) => $c['channel'], array_filter(self::all(), static fn ($c) => $c['ready'])));
    }

    /** Normalises a requested channel name ("x" → "twitter"); null when unknown. */
    public static function normalize(string $channel): ?string
    {
        $c = strtolower(trim($channel));
        $c = match ($c) {
            'x', 'x/twitter', 'twitter/x' => 'twitter',
            'instagram reels', 'reels', 'ig' => 'instagram',
            'youtube shorts', 'shorts', 'yt' => 'youtube',
            'fb' => 'facebook',
            default => $c,
        };
        return in_array($c, self::CHANNELS, true) ? $c : null;
    }
}
