<?php

declare(strict_types=1);

namespace App\Libraries\Content;

/**
 * The brand as aicountly.com writes it (owner decision, 23 Oct 2026):
 *
 *   - the brand word is "Aicountly" — AICOUNTLY, AICountly, AiCountly and
 *     AIcountly become Aicountly (also inside "AICOUNTLY-branded", "#AICOUNTLY");
 *   - hostnames are lowercase (AICOUNTLY.com → aicountly.com, also in e-mail
 *     addresses and in the host part of a URL);
 *   - the legal name is exactly "Aicountly Interactive Services Private Limited".
 *
 * Never changed: URL paths and queries, @handles, identifiers such as
 * AICOUNTLY_PUBLIC_SITE_BASE_URL or MyAICOUNTLYClone, lowercase "aicountly"
 * (slugs, handles). In HTML only text nodes and the human-readable alt /
 * title / aria-label attributes change — never href, src, ids, classes, nor
 * <script>, <style>, <pre> or <code>. In Markdown, code spans/blocks and link
 * targets are left alone. In JSON, values under identifier-like keys (slug,
 * url, id, …) are left alone.
 *
 * Pure and idempotent: normalising twice gives the same result as once.
 */
final class BrandCasing
{
    public const BRAND = 'Aicountly';
    public const LEGAL = 'Aicountly Interactive Services Private Limited';

    private const LEGAL_RE = '~\bAICOUNTLY\s+INTERACTIVE\s+SERVICES\s+(?:PRIVATE|PVT\.?)\s+(?:LIMITED|LTD\b\.?)~i';
    private const HOST_RE  = '~\b(?:[a-z0-9-]+\.)*aicountly\.(?:com|org|in|io|ai|net)\b~i';
    private const BRAND_RE = '~(?<![\w@/])(?:AICOUNTLY|AICountly|AiCountly|AIcountly)(?!\w)~';
    private const URL_RE   = '~\b[a-z][a-z0-9+.-]*://[^\s<>"\'`]+~i';

    /** Identifier-like JSON keys whose values are never rewritten. */
    private const ID_KEY_RE = '~^(?:@id|@type|@context|id|uuid|slug|url|urls|href|src|link|links|image|images|logo|sameas|canonical|email|handle|username|repo|path|ref|sha|hash|checksum|key|channel|channels|media|attachments|platform|status|category|product|format|locale|language)$|_(?:id|ids|uuid|slug|slugs|url|urls|href|src|key|keys|path|ref|sha|hash|checksum|email|handle|status|type)$~i';

    /** Plain text (titles, excerpts, meta fields, social copy). */
    public static function text(string $s): string
    {
        if (! self::mayNeed($s)) {
            return $s;
        }

        // URLs: only the scheme and host are lower-cased; the path stays.
        $parts = preg_split(self::URL_RE, $s, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [[$s, 0]];
        preg_match_all(self::URL_RE, $s, $urls);
        $out = '';
        foreach ($parts as $i => [$chunk]) {
            $out .= self::prose($chunk);
            if (isset($urls[0][$i])) {
                $out .= self::url($urls[0][$i]);
            }
        }

        return $out;
    }

    /** HTML: text nodes and alt / title / aria-label only. */
    public static function html(string $s): string
    {
        if (! self::mayNeed($s)) {
            return $s;
        }
        $tokens = preg_split(
            '~(<!--.*?-->|<script\b.*?</script\s*>|<style\b.*?</style\s*>|<pre\b.*?</pre\s*>|<code\b.*?</code\s*>|<[^>]*>)~is',
            $s,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        ) ?: [$s];

        $out = '';
        foreach ($tokens as $t) {
            if ($t === '') {
                continue;
            }
            if ($t[0] !== '<') {
                $out .= self::text($t);
            } elseif (preg_match('~^<(?:!--|script\b|style\b|pre\b|code\b)~i', $t) === 1) {
                $out .= $t;
            } else {
                $out .= (string) preg_replace_callback(
                    '~(\s(?:alt|title|aria-label)\s*=\s*)("([^"]*)"|\'([^\']*)\')~i',
                    static function (array $m): string {
                        $quote = $m[2][0];
                        $value = $quote === '"' ? $m[3] : ($m[4] ?? '');

                        return $m[1] . $quote . self::text($value) . $quote;
                    },
                    $t
                );
            }
        }

        return $out;
    }

    /** Markdown: code spans/blocks and link targets are left alone. */
    public static function markdown(string $s): string
    {
        if (! self::mayNeed($s)) {
            return $s;
        }
        $tokens = preg_split('~(```.*?```|`[^`\n]*`|\]\([^)\n]*\))~s', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
        $out    = '';
        foreach ($tokens as $t) {
            $out .= ($t !== '' && ($t[0] === '`' || str_starts_with($t, ']('))) ? $t : self::html($t);
        }

        return $out;
    }

    /**
     * A decoded JSON value: strings are normalised (as HTML when they hold
     * tags), except under identifier-like keys.
     */
    public static function json(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::ID_KEY_RE, $key) === 1) {
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::json($v, is_string($k) ? $k : $key);
            }

            return $out;
        }
        if (is_string($value)) {
            return preg_match('~<[a-z][^>]*>~i', $value) === 1 ? self::html($value) : self::text($value);
        }

        return $value;
    }

    /** A JSON document as stored; null / invalid JSON comes back unchanged. */
    public static function jsonString(?string $raw): ?string
    {
        if ($raw === null || $raw === '' || ! self::mayNeed($raw)) {
            return $raw;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }
        $fixed = self::json($decoded);

        return $fixed === $decoded ? $raw : json_encode($fixed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Cheap pre-check: is there anything to change at all? */
    public static function mayNeed(string $s): bool
    {
        if (stripos($s, 'aicountly') === false) {
            return false;
        }
        if (preg_match('~AICOUNTLY|AICountly|AiCountly|AIcountly~', $s) === 1) {
            return true;
        }
        if (preg_match_all(self::HOST_RE, $s, $m) > 0) {
            foreach ($m[0] as $host) {
                if ($host !== strtolower($host)) {
                    return true;
                }
            }
        }
        if (preg_match_all(self::LEGAL_RE, $s, $m) > 0) {
            foreach ($m[0] as $legal) {
                if ($legal !== self::LEGAL) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function prose(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $s = (string) preg_replace(self::LEGAL_RE, self::LEGAL, $s);
        $s = (string) preg_replace_callback(self::HOST_RE, static fn (array $m): string => strtolower($m[0]), $s);

        return (string) preg_replace(self::BRAND_RE, self::BRAND, $s);
    }

    /** Lower-cases an Aicountly host in a URL; the path and query stay as they are. */
    private static function url(string $url): string
    {
        return (string) preg_replace_callback(
            '~^([a-z][a-z0-9+.-]*://)([^/?#\s]*)~i',
            static fn (array $m): string => preg_match('~(?:^|[.@])aicountly\.(?:com|org|in|io|ai|net)(?::\d+)?$~i', $m[2]) === 1
                ? strtolower($m[1] . $m[2])
                : $m[0],
            $url
        );
    }
}
