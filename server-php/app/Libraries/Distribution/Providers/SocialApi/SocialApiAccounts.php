<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers\SocialApi;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Cache\CacheInterface;

/**
 * Which social-api.ai account a post for a platform goes to.
 *
 *   1. `social.socialapi.account.<platform>` set → that id, no API call.
 *   2. Otherwise the single *active* account of that platform in GET /accounts
 *      (cached 10 minutes per key + base URL).
 *   3. None, or more than one → fail closed with a message that names what
 *      was found and the setting to fill in.
 *
 * ASSUMPTION: an account is active when its `status` is missing or one of
 * active / connected / ok / healthy / valid / enabled / ready / live. Any
 * other status (expired, disconnected, a word Reach does not know) is not
 * auto-picked — the operator can still pin the id in the setting.
 */
final class SocialApiAccounts
{
    public const CACHE_TTL_SECS   = 600;
    /** A failed listing is remembered (per process only) this long. */
    public const FAILURE_TTL_SECS = 60;

    private const ACTIVE   = ['active', 'connected', 'ok', 'healthy', 'valid', 'enabled', 'ready', 'live', 'authorized', 'authorised'];
    private const INACTIVE = ['inactive', 'disconnected', 'expired', 'error', 'revoked', 'disabled', 'invalid', 'reauth_required', 'needs_reauth', 'reconnect_required', 'paused', 'suspended', 'deleted'];

    /** @var array<string, array{expires: int, accounts: list<array<string, mixed>>, error?: SocialApiException}> per-process memo */
    private static array $memo = [];

    private ?CacheInterface $cache;
    /** @var callable(): int */
    private $clock;

    public function __construct(private readonly SocialApiAiClient $client, ?CacheInterface $cache = null, ?callable $clock = null, bool $useAppCache = true)
    {
        $this->cache = $cache ?? ($useAppCache ? self::appCache() : null);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @internal tests / after a key change */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /**
     * Every connected account, normalized:
     *   id, name, username, platform (PLATFORMS entry or null), platform_raw,
     *   platform_field, status (raw), active (bool)
     *
     * @return list<array<string, mixed>>
     * @throws SocialApiException
     */
    public function all(bool $fresh = false): array
    {
        $key = 'reach_socialapi_accounts_' . $this->client->fingerprint();
        $now = ($this->clock)();
        if (! $fresh) {
            if (isset(self::$memo[$key]) && self::$memo[$key]['expires'] > $now) {
                if (isset(self::$memo[$key]['error'])) {
                    // The same failure, without asking again in this process for a minute
                    // (e.g. the readiness page checks five channels in one request).
                    throw self::$memo[$key]['error'];
                }
                return self::$memo[$key]['accounts'];
            }
            $cached = $this->cacheGet($key);
            if (is_array($cached) && isset($cached['expires'], $cached['accounts']) && $cached['expires'] > $now) {
                self::$memo[$key] = $cached;
                return $cached['accounts'];
            }
        }

        try {
            $rawAccounts = $this->client->accounts();
        } catch (SocialApiException $e) {
            self::$memo[$key] = ['expires' => $now + self::FAILURE_TTL_SECS, 'accounts' => [], 'error' => $e];
            throw $e;
        }

        $accounts = [];
        $unknown  = [];
        foreach ($rawAccounts as $raw) {
            $a = self::normalize($raw);
            if ($a['id'] === null) {
                $unknown[] = 'account without an id (keys: ' . implode(', ', array_slice(array_map('strval', array_keys($raw)), 0, 10)) . ')';
                continue;
            }
            if ($a['platform'] === null) {
                $unknown[] = $a['platform_field'] === null
                    ? "account {$a['id']}: no platform field (keys: " . implode(', ', array_slice(array_map('strval', array_keys($raw)), 0, 10)) . ')'
                    : "account {$a['id']}: {$a['platform_field']}=" . ($a['platform_raw'] ?? '?') . ' is not a platform Reach publishes to';
            }
            $accounts[] = $a;
        }
        if ($unknown !== [] && function_exists('log_message')) {
            log_message('warning', '[SocialApiAi] GET /accounts: ' . $this->client->redactor()->string(implode('; ', array_slice($unknown, 0, 10))));
        }

        $entry = ['expires' => $now + self::CACHE_TTL_SECS, 'accounts' => $accounts];
        self::$memo[$key] = $entry;
        $this->cacheSave($key, $entry);

        return $accounts;
    }

    /**
     * The account a post on $platform goes to.
     *
     * @return array{id: string, name: ?string, username: ?string, source: string}
     * @throws SocialApiException not_configured (none / ambiguous), or the listing error
     */
    public function resolve(string $platform): array
    {
        $label    = SocialApiPlatforms::label($platform);
        $override = trim((string) ReachConfig::get('social.socialapi.account.' . $platform, ''));
        if ($override !== '') {
            return ['id' => $override, 'name' => null, 'username' => null, 'source' => 'setting'];
        }

        $all     = $this->all();
        $mine    = array_values(array_filter($all, static fn ($a) => $a['platform'] === $platform));
        $active  = array_values(array_filter($mine, static fn ($a) => $a['active']));
        $setting = 'social.socialapi.account.' . $platform;

        if (count($active) === 1) {
            return ['id' => (string) $active[0]['id'], 'name' => $active[0]['name'], 'username' => $active[0]['username'], 'source' => 'auto'];
        }
        if (count($active) > 1) {
            throw new SocialApiException(
                sprintf('%d active %s accounts are connected in social-api.ai (%s). Set %s to the id Reach should post to. Nothing was posted.', count($active), $label, self::describe($active), $setting),
                SocialApiException::NOT_CONFIGURED,
            );
        }
        $inactive = $mine !== [] ? ' Found but not active: ' . self::describe($mine) . '.' : '';
        $seen     = $all !== [] ? ' Connected accounts: ' . self::describe(array_slice($all, 0, 8)) . '.' : ' social-api.ai lists no connected accounts.';
        throw new SocialApiException(
            "No active {$label} account is connected in social-api.ai.{$inactive}{$seen} Connect {$label} in social-api.ai, or set {$setting}. Nothing was posted.",
            SocialApiException::NOT_CONFIGURED,
        );
    }

    /**
     * resolve() without throwing, for readiness / health views.
     *
     * @return array{ok: bool, account: ?array<string, mixed>, reason: ?string, kind: ?string}
     */
    public function check(string $platform): array
    {
        try {
            return ['ok' => true, 'account' => $this->resolve($platform), 'reason' => null, 'kind' => null];
        } catch (SocialApiException $e) {
            return ['ok' => false, 'account' => null, 'reason' => $e->getMessage(), 'kind' => $e->kind];
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => null, 'reason' => 'social-api.ai accounts could not be read: ' . $this->client->redactor()->string($e->getMessage()), 'kind' => 'error'];
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $id = $raw['id'] ?? $raw['account_id'] ?? $raw['accountId'] ?? null;
        $p  = SocialApiPlatforms::ofAccount($raw);
        $status = $raw['status'] ?? $raw['state'] ?? null;
        $status = is_string($status) ? strtolower(trim($status)) : null;
        $activeFlag = $raw['active'] ?? $raw['is_active'] ?? null;

        $active = match (true) {
            is_bool($activeFlag)                          => $activeFlag && ($status === null || ! in_array($status, self::INACTIVE, true)),
            $status === null || $status === ''            => true,
            in_array($status, self::ACTIVE, true)         => true,
            default                                       => false,
        };

        return [
            'id'             => is_scalar($id) && (string) $id !== '' ? (string) $id : null,
            'name'           => is_scalar($raw['name'] ?? null) ? mb_substr((string) $raw['name'], 0, 120) : (is_scalar($raw['display_name'] ?? null) ? mb_substr((string) $raw['display_name'], 0, 120) : null),
            'username'       => is_scalar($raw['username'] ?? null) ? mb_substr((string) $raw['username'], 0, 120) : (is_scalar($raw['handle'] ?? null) ? mb_substr((string) $raw['handle'], 0, 120) : null),
            'platform'       => $p['platform'],
            'platform_raw'   => $p['raw'],
            'platform_field' => $p['field'],
            'status'         => $status,
            'active'         => $active,
        ];
    }

    /** @param list<array<string, mixed>> $accounts */
    public static function describe(array $accounts): string
    {
        return implode(', ', array_map(static function (array $a): string {
            $name = $a['name'] ?? $a['username'] ?? null;
            return trim(($a['platform'] ?? ($a['platform_raw'] ?? '?')) . ' ' . ($name !== null ? '“' . $name . '” ' : '') . $a['id'] . ($a['status'] !== null && ! $a['active'] ? " ({$a['status']})" : ''));
        }, $accounts));
    }

    private static function appCache(): ?CacheInterface
    {
        // Under PHPUnit only the per-process memo is used, so one test's accounts
        // can never reach another through the file cache.
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'testing') {
            return null;
        }
        try {
            return \Config\Services::cache();
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache?->get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheSave(string $key, array $entry): void
    {
        try {
            $this->cache?->save($key, $entry, self::CACHE_TTL_SECS);
        } catch (\Throwable) {
        }
    }
}
