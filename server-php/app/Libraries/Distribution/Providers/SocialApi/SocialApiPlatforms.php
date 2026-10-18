<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers\SocialApi;

/**
 * Reach channel names ↔ social-api.ai platform names, and a tolerant reading
 * of the platform an Account object belongs to.
 *
 * ASSUMPTION (docs.social-api.ai only says the platform is identifiable per
 * account): the account carries it in one of `platform`, `provider`,
 * `network`, `platform_type`, `type` or `channel`, as a string or as an
 * object with `id` / `name` / `slug` / `key`. Values are matched loosely
 * ("youtube_channel", "instagram_business", "linkedin_page", "twitter" …).
 * Anything else is logged by SocialApiAccounts and never guessed at.
 */
final class SocialApiPlatforms
{
    /** social-api.ai platforms Reach can configure (settings social.socialapi.account.<p> / platform_data.<p>). */
    public const PLATFORMS = ['youtube', 'instagram', 'facebook', 'linkedin', 'x', 'tiktok'];

    public const LABELS = [
        'youtube'   => 'YouTube',
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'linkedin'  => 'LinkedIn',
        'x'         => 'X / Twitter',
        'tiktok'    => 'TikTok',
    ];

    /** Reach social channels (reach_social_posts.channel) that social-api.ai can carry. */
    public const CHANNELS = ['linkedin', 'twitter', 'facebook', 'instagram', 'youtube'];

    /** Platforms whose posts cannot go out without a video / image. */
    public const MEDIA_REQUIRED = ['youtube' => 'a video', 'instagram' => 'an image or video', 'tiktok' => 'a video'];

    public const PLATFORM_FIELDS = ['platform', 'provider', 'network', 'platform_type', 'type', 'channel'];

    public static function forChannel(string $channel): ?string
    {
        $c = strtolower(trim($channel));
        if ($c === 'twitter') {
            return 'x';
        }
        return in_array($c, self::PLATFORMS, true) ? $c : null;
    }

    public static function label(string $platform): string
    {
        return self::LABELS[$platform] ?? ucfirst($platform);
    }

    /** A platform value from the API ("YouTube", "youtube_channel", "twitter") → a PLATFORMS entry, or null. */
    public static function normalize(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach (['slug', 'key', 'id', 'name', 'code'] as $k) {
                if (isset($value[$k]) && is_string($value[$k])) {
                    $hit = self::normalize($value[$k]);
                    if ($hit !== null) {
                        return $hit;
                    }
                }
            }
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $v = strtolower(trim($value));
        if (in_array($v, ['x', 'twitter', 'x_twitter', 'twitter_x', 'x.com'], true) || str_starts_with($v, 'twitter')) {
            return 'x';
        }
        foreach (['youtube', 'instagram', 'facebook', 'linkedin', 'tiktok'] as $p) {
            if (str_contains($v, $p)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Which platform an Account object is, and which field said so.
     *
     * @param array<string, mixed> $account
     * @return array{platform: ?string, field: ?string, raw: ?string}
     */
    public static function ofAccount(array $account): array
    {
        foreach (self::PLATFORM_FIELDS as $field) {
            if (! array_key_exists($field, $account) || $account[$field] === null || $account[$field] === '') {
                continue;
            }
            $raw = $account[$field];
            $hit = self::normalize($raw);
            $rawText = is_string($raw) ? $raw : (is_array($raw) ? (string) json_encode($raw, JSON_UNESCAPED_SLASHES) : (string) $raw);
            if ($hit !== null) {
                return ['platform' => $hit, 'field' => $field, 'raw' => mb_substr($rawText, 0, 80)];
            }
            // `type` / `channel` are commonly something else (e.g. "page"); keep looking.
            if (in_array($field, ['platform', 'provider', 'network', 'platform_type'], true)) {
                return ['platform' => null, 'field' => $field, 'raw' => mb_substr($rawText, 0, 80)];
            }
        }
        return ['platform' => null, 'field' => null, 'raw' => null];
    }
}
