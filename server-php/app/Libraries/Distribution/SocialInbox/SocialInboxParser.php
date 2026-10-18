<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\SocialInbox;

use App\Libraries\Distribution\Providers\SocialApi\SocialApiPlatforms;

/**
 * Reads social-api.ai inbox answers tolerantly. The shape is an ASSUMPTION
 * until the first live run (docs/operations/SOCIAL_API.md → Social inbox bot):
 *
 *   page    a JSON list, or a list under data / items / results / messages /
 *           comments / conversations / threads / inbox (also one level under
 *           `data`); a conversation-like item carrying `messages` / `comments` /
 *           `replies` is flattened, its id becoming each child's thread id
 *   cursor  next_cursor | cursor | next | after | pagination.{next_cursor,next,cursor}
 *           | meta.{next_cursor,next} | paging.cursors.after — a URL is read for
 *           its cursor / after / page_token parameter
 *   item    id | message_id | comment_id | item_id; text | message | content | body
 *           | comment | caption; author | from | sender | user | commenter
 *           {name | display_name, username | handle | screen_name, id}; …
 *
 * Nothing is guessed: an item without an id or text is dropped and counted in
 * `skipped` with the keys it had, so the log shows what social-api.ai sent.
 */
final class SocialInboxParser
{
    private const LIST_KEYS     = ['data', 'items', 'results', 'messages', 'comments', 'conversations', 'threads', 'inbox', 'entries'];
    private const CHILD_KEYS    = ['messages', 'comments', 'replies'];
    private const ID_KEYS       = ['id', 'message_id', 'comment_id', 'item_id', 'messageId', 'commentId', 'uuid'];
    private const TEXT_KEYS     = ['text', 'message', 'content', 'body', 'comment', 'caption', 'snippet'];
    private const AUTHOR_KEYS   = ['author', 'from', 'sender', 'user', 'commenter', 'participant', 'contact'];
    private const URL_KEYS      = ['post_url', 'permalink', 'permalink_url', 'post_permalink', 'url', 'link', 'web_url', 'platform_url'];
    private const TIME_KEYS     = ['created_at', 'createdAt', 'received_at', 'timestamp', 'created_time', 'sent_at', 'date', 'time', 'published_at'];
    private const THREAD_KEYS   = ['thread_id', 'conversation_id', 'conversationId', 'threadId', 'root_id', 'parent_post_id', 'post_id', 'postId', 'media_id'];
    private const OUTBOUND_DIRS = ['outbound', 'outgoing', 'sent', 'out'];

    /**
     * @param array<string, mixed>|list<mixed> $json
     * @return array{items: list<array<string, mixed>>, next: ?string, recognised: bool, keys: list<string>, skipped: list<string>}
     */
    public static function page(array $json, ?string $platform = null, ?string $accountId = null): array
    {
        [$list, $container] = self::locateList($json);
        $keys = array_slice(array_map('strval', array_keys($json)), 0, 15);
        if ($list === null) {
            return ['items' => [], 'next' => self::cursor($json), 'recognised' => false, 'keys' => $keys, 'skipped' => []];
        }

        $items   = [];
        $skipped = [];
        foreach ($list as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $children = null;
            foreach (self::CHILD_KEYS as $ck) {
                if (isset($raw[$ck]) && is_array($raw[$ck]) && array_is_list($raw[$ck]) && $raw[$ck] !== [] && is_array($raw[$ck][0])) {
                    $children = $raw[$ck];
                    break;
                }
            }
            if ($children !== null && self::firstString($raw, self::TEXT_KEYS) === null) {
                // A conversation / post with its messages: flatten, inheriting context.
                $parentId = self::firstScalar($raw, self::ID_KEYS);
                foreach ($children as $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    $inherit = array_filter([
                        'thread_id'  => $child['thread_id'] ?? $child['conversation_id'] ?? $parentId,
                        'platform'   => $child['platform'] ?? $raw['platform'] ?? $raw['provider'] ?? $raw['network'] ?? null,
                        'account_id' => $child['account_id'] ?? $raw['account_id'] ?? (is_array($raw['account'] ?? null) ? ($raw['account']['id'] ?? null) : null),
                        'post_url'   => $child['post_url'] ?? $raw['post_url'] ?? $raw['permalink'] ?? $raw['url'] ?? null,
                        'type'       => $child['type'] ?? $child['kind'] ?? $raw['type'] ?? $raw['kind'] ?? null,
                    ], static fn ($v) => $v !== null && $v !== '');
                    $n = self::item($child + $inherit, $platform, $accountId);
                    $n === null ? $skipped[] = self::why($child) : $items[] = $n;
                }
                continue;
            }
            $n = self::item($raw, $platform, $accountId);
            $n === null ? $skipped[] = self::why($raw) : $items[] = $n;
        }

        return ['items' => $items, 'next' => self::cursor($container) ?? self::cursor($json), 'recognised' => true, 'keys' => $keys, 'skipped' => $skipped];
    }

    /**
     * One inbox item → the fields Reach stores, or null when it has no id or no text.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    public static function item(array $raw, ?string $platform = null, ?string $accountId = null): ?array
    {
        $id   = self::firstScalar($raw, self::ID_KEYS);
        $text = self::text($raw);
        if ($id === null || $text === null) {
            return null;
        }

        $author = null;
        foreach (self::AUTHOR_KEYS as $k) {
            if (isset($raw[$k]) && is_array($raw[$k])) {
                $author = $raw[$k];
                break;
            }
        }
        $authorName   = $author !== null ? self::firstString($author, ['name', 'display_name', 'displayName', 'full_name', 'title']) : null;
        $authorHandle = $author !== null ? self::firstString($author, ['username', 'handle', 'screen_name', 'screenName', 'login', 'vanity_name']) : null;
        $authorId     = $author !== null ? self::firstScalar($author, ['id', 'user_id', 'platform_user_id', 'external_id', 'urn']) : null;
        $authorName   ??= self::firstString($raw, ['author_name', 'from_name', 'sender_name', 'user_name', 'display_name']);
        $authorHandle ??= self::firstString($raw, ['author_username', 'author_handle', 'username', 'handle', 'from_username', 'screen_name']);
        $authorId     ??= self::firstScalar($raw, ['author_id', 'from_id', 'sender_id', 'user_id']);
        if (is_string($raw['author'] ?? null) && $authorName === null && $authorHandle === null) {
            $authorHandle = $raw['author'];
        }

        $p = null;
        foreach (['platform', 'provider', 'network', 'platform_type', 'channel'] as $k) {
            if (isset($raw[$k])) {
                $p = SocialApiPlatforms::normalize($raw[$k]);
                if ($p !== null) {
                    break;
                }
            }
        }
        $acc = self::firstScalar($raw, ['account_id', 'accountId']) ?? (is_array($raw['account'] ?? null) ? self::firstScalar($raw['account'], ['id', 'account_id']) : null);

        $postUrl = null;
        foreach ([$raw, is_array($raw['post'] ?? null) ? $raw['post'] : [], is_array($raw['media'] ?? null) ? $raw['media'] : []] as $src) {
            foreach (self::URL_KEYS as $k) {
                if (isset($src[$k]) && is_string($src[$k]) && self::isHttps($src[$k])) {
                    $postUrl = mb_substr($src[$k], 0, 2000);
                    break 2;
                }
            }
        }

        $thread = self::firstScalar($raw, self::THREAD_KEYS)
            ?? (is_array($raw['post'] ?? null) ? self::firstScalar($raw['post'], ['id', 'post_id']) : null)
            ?? (is_array($raw['conversation'] ?? null) ? self::firstScalar($raw['conversation'], ['id']) : null);

        return [
            'external_id'        => mb_substr($id, 0, 191),
            'kind'               => self::kind($raw),
            'platform'           => $p ?? $platform,
            'account_id'         => $acc !== null ? mb_substr($acc, 0, 191) : $accountId,
            'author_name'        => $authorName !== null ? mb_substr($authorName, 0, 200) : null,
            'author_handle'      => $authorHandle !== null ? mb_substr(ltrim($authorHandle, '@'), 0, 200) : null,
            'author_external_id' => $authorId !== null ? mb_substr($authorId, 0, 191) : null,
            'text'               => mb_substr($text, 0, 8000),
            'post_url'           => $postUrl,
            'thread_id'          => $thread !== null ? mb_substr($thread, 0, 191) : null,
            'parent_id'          => ($v = self::firstScalar($raw, ['parent_id', 'parentId', 'in_reply_to', 'reply_to_id'])) !== null ? mb_substr($v, 0, 191) : null,
            'received_at'        => self::time($raw),
            'is_own'             => self::isOwn($raw, $author),
            'is_deleted'         => self::isDeleted($raw),
            'raw'                => $raw,
        ];
    }

    /** comment | message | mention | review | other */
    public static function kind(array $raw): string
    {
        $t = '';
        foreach (['type', 'kind', 'item_type', 'object', 'source_type', 'category'] as $k) {
            if (isset($raw[$k]) && is_string($raw[$k]) && trim($raw[$k]) !== '') {
                $t = strtolower(trim($raw[$k]));
                break;
            }
        }
        return match (true) {
            $t === ''                                                                 => isset($raw['conversation_id']) ? 'message' : 'comment',
            str_contains($t, 'mention')                                               => 'mention',
            str_contains($t, 'review') || str_contains($t, 'rating')                  => 'review',
            str_contains($t, 'comment') || str_contains($t, 'reply')                  => 'comment',
            str_contains($t, 'message') || preg_match('/(^|[^a-z])dms?([^a-z]|$)/', $t) === 1 || str_contains($t, 'direct')
                || str_contains($t, 'conversation') || str_contains($t, 'chat')       => 'message',
            default                                                                   => 'other',
        };
    }

    /** The next-page cursor, or null. */
    public static function cursor(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }
        $candidates = [
            $json['next_cursor'] ?? null, $json['nextCursor'] ?? null, $json['cursor'] ?? null, $json['next'] ?? null, $json['after'] ?? null,
            $json['pagination']['next_cursor'] ?? null, $json['pagination']['next'] ?? null, $json['pagination']['cursor'] ?? null,
            $json['meta']['next_cursor'] ?? null, $json['meta']['next'] ?? null, $json['paging']['cursors']['after'] ?? null, $json['paging']['next'] ?? null,
        ];
        if (($json['has_more'] ?? $json['hasMore'] ?? $json['pagination']['has_more'] ?? null) === false) {
            return null;
        }
        foreach ($candidates as $c) {
            if (! is_scalar($c) || is_bool($c) || trim((string) $c) === '') {
                continue;
            }
            $c = (string) $c;
            if (preg_match('#^https?://#i', $c)) {
                parse_str((string) parse_url($c, PHP_URL_QUERY), $q);
                foreach (['cursor', 'after', 'page_token', 'next_cursor', 'page'] as $k) {
                    if (isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '') {
                        return mb_substr($q[$k], 0, 500);
                    }
                }
                continue;
            }
            return mb_substr($c, 0, 500);
        }
        return null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|list<mixed> $json
     * @return array{0: ?list<mixed>, 1: array<string, mixed>|list<mixed>}
     */
    /**
     * The structure of an answer with every value replaced by its type — key
     * names, nesting and list lengths only, never message text, names or ids —
     * so an unrecognised inbox answer can be read off the live check safely.
     *
     * @param mixed $value
     */
    public static function skeleton($value, int $depth = 0): string
    {
        if (is_array($value)) {
            if ($depth >= 6) {
                return array_is_list($value) ? 'list(' . count($value) . ')' : 'object';
            }
            if (array_is_list($value)) {
                return $value === [] ? 'list(0)' : 'list(' . count($value) . ') of ' . self::skeleton($value[0], $depth + 1);
            }
            $parts = [];
            foreach (array_slice($value, 0, 20, true) as $k => $v) {
                $parts[] = mb_substr((string) $k, 0, 40) . ': ' . self::skeleton($v, $depth + 1);
            }
            return '{' . implode(', ', $parts) . (count($value) > 20 ? ', …' : '') . '}';
        }

        return match (true) {
            $value === null  => 'null',
            is_bool($value)  => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            default          => 'string',
        };
    }

    private static function locateList(array $json): array
    {
        if (array_is_list($json)) {
            return [$json, $json];
        }
        foreach (self::LIST_KEYS as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                if (array_is_list($json[$k])) {
                    return [$json[$k], $json];
                }
                foreach (self::LIST_KEYS as $k2) {
                    if (isset($json[$k][$k2]) && is_array($json[$k][$k2]) && array_is_list($json[$k][$k2])) {
                        return [$json[$k][$k2], $json[$k]];
                    }
                }
            }
        }
        return [null, $json];
    }

    private static function text(array $raw): ?string
    {
        $t = self::firstString($raw, self::TEXT_KEYS);
        if ($t === null) {
            foreach (['content', 'message', 'body'] as $k) {
                if (isset($raw[$k]) && is_array($raw[$k])) {
                    $t = self::firstString($raw[$k], ['text', 'body', 'value']);
                    if ($t !== null) {
                        break;
                    }
                }
            }
        }
        if ($t === null) {
            return null;
        }
        $t = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $t) ?? '');
        return $t !== '' ? $t : null;
    }

    private static function time(array $raw): ?string
    {
        foreach (self::TIME_KEYS as $k) {
            $v = $raw[$k] ?? null;
            if (is_int($v) || is_float($v) || (is_string($v) && ctype_digit($v))) {
                $n = (int) $v;
                $n = $n > 100_000_000_000 ? intdiv($n, 1000) : $n;
                if ($n > 946684800) {
                    return gmdate('Y-m-d H:i:s', $n) . '+00';
                }
            } elseif (is_string($v) && trim($v) !== '') {
                $ts = strtotime($v);
                if ($ts !== false) {
                    return gmdate('Y-m-d H:i:s', $ts) . '+00';
                }
            }
        }
        return null;
    }

    private static function isOwn(array $raw, ?array $author): bool
    {
        foreach (['is_from_me', 'from_me', 'is_own', 'is_outgoing', 'outgoing', 'is_echo', 'from_page', 'is_page_reply', 'is_brand', 'sent_by_us'] as $k) {
            if (($raw[$k] ?? null) === true) {
                return true;
            }
        }
        $dir = $raw['direction'] ?? null;
        if (is_string($dir) && in_array(strtolower($dir), self::OUTBOUND_DIRS, true)) {
            return true;
        }
        if ($author !== null) {
            foreach (['is_self', 'is_me', 'is_owner', 'is_page', 'is_account_owner'] as $k) {
                if (($author[$k] ?? null) === true) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function isDeleted(array $raw): bool
    {
        if (($raw['deleted'] ?? $raw['is_deleted'] ?? null) === true) {
            return true;
        }
        $s = $raw['status'] ?? null;
        return is_string($s) && in_array(strtolower($s), ['deleted', 'removed'], true);
    }

    private static function isHttps(string $url): bool
    {
        return preg_match('#^https://[^\s/?\#]+#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** @param list<string> $keys */
    private static function firstString(array $a, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && is_string($a[$k]) && trim($a[$k]) !== '') {
                return trim($a[$k]);
            }
        }
        return null;
    }

    /** @param list<string> $keys */
    private static function firstScalar(array $a, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && (is_string($a[$k]) || is_int($a[$k])) && trim((string) $a[$k]) !== '') {
                return trim((string) $a[$k]);
            }
        }
        return null;
    }

    private static function why(array $raw): string
    {
        return 'item without ' . (self::firstScalar($raw, self::ID_KEYS) === null ? 'an id' : 'text')
            . ' (keys: ' . implode(', ', array_slice(array_map('strval', array_keys($raw)), 0, 12)) . ')';
    }
}
