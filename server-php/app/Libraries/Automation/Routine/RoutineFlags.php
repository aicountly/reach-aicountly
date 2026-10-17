<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Settings\ReachConfig;

/**
 * The switches the Claude-routine blog/KB pipeline obeys.
 *
 * Each switch is a Settings → AI & Automation value (so the owner can turn it
 * on or off without a shell) plus the server .env variables that have always
 * meant "off". An env variable set explicitly to false is a hard kill-switch
 * the setting cannot override; unset (or true) defers to the setting.
 *
 * The pipeline-route BlogFeatureFlags are deliberately left alone: those gate
 * Reach's own AI generation pipeline, which stays off by default.
 */
final class RoutineFlags
{
    /** flag => [ReachConfig key, kill-switch env vars] */
    private const FLAGS = [
        'blog.enabled'           => ['automation.blog.enabled', []],
        'blog.auto_publish'      => ['automation.blog.auto_publish', ['BLOG_AUTO_PUBLISH_ENABLED', 'BLOG_PUBLIC_PUBLISHER_ENABLED']],
        'blog.ai_cover'          => ['automation.blog.ai_cover', ['BLOG_IMAGE_GENERATION_ENABLED']],
        'knowledge_base.enabled' => ['automation.knowledge_base.enabled', []],
        'knowledge_base.auto_publish' => ['automation.knowledge_base.auto_publish', ['KB_AUTO_PUBLISH_ENABLED']],
    ];

    public static function enabled(string $flag): bool
    {
        return self::explain($flag)['enabled'];
    }

    /**
     * @return array{enabled: bool, source: string, kill_switch: ?string}
     *   source: 'settings' (the Settings value decides), 'env_kill_switch'
     */
    public static function explain(string $flag): array
    {
        if (! isset(self::FLAGS[$flag])) {
            throw new \InvalidArgumentException("Unknown routine flag: {$flag}");
        }
        [$key, $envVars] = self::FLAGS[$flag];

        foreach ($envVars as $var) {
            if (self::explicitlyFalse($var)) {
                return ['enabled' => false, 'source' => 'env_kill_switch', 'kill_switch' => $var];
            }
        }

        return ['enabled' => (bool) ReachConfig::get($key, false), 'source' => 'settings', 'kill_switch' => null];
    }

    /** @return array<string, array{enabled: bool, source: string, kill_switch: ?string}> */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FLAGS) as $flag) {
            $out[$flag] = self::explain($flag);
        }
        return $out;
    }

    private static function explicitlyFalse(string $var): bool
    {
        $v = $_ENV[$var] ?? $_SERVER[$var] ?? getenv($var);
        if ($v === false || $v === null) {
            return false;
        }
        if (is_bool($v)) {
            return $v === false;
        }
        $v = strtolower(trim((string) $v));

        return in_array($v, ['0', 'false', 'no', 'off'], true);
    }
}
