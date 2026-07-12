<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * SSRF-safe URL validator.
 *
 * Blocks:
 *   - non-http(s) schemes
 *   - URLs containing userinfo (`http://user:pass@host/`)
 *   - unresolved hosts, empty hosts, or IP literals in reserved ranges:
 *       * loopback (127.0.0.0/8, ::1)
 *       * private ranges (10/8, 172.16/12, 192.168/16, fc00::/7, fe80::/10)
 *       * link-local (169.254/16) — includes AWS metadata 169.254.169.254
 *       * multicast, broadcast, this-network, TEST-NET, reserved future
 *   - well-known cloud metadata hostnames (metadata.google.internal,
 *     metadata.aws, kubernetes.default.svc, etc.)
 *
 * DNS is resolved (getaddrinfo-style via `dns_get_record`) so an attacker
 * cannot bypass by pointing a hostname at 127.0.0.1.
 *
 * Callers pass `$opts['allowedHosts']` to override the deny-list for known-
 * good destinations (e.g. publishing/CDN hosts registered via env). The
 * allow list is checked BEFORE DNS resolution so we do not leak lookups
 * for internal probes.
 */
class UrlPolicy
{
    /**
     * Hostnames that are ALWAYS denied, even if they resolve to public IPs.
     * These are cloud metadata endpoints which return sensitive credentials.
     */
    private const METADATA_HOSTS = [
        'metadata.google.internal',
        'metadata.aws',
        'metadata.aws.amazon.com',
        'metadata',
        'kubernetes.default.svc',
        'kubernetes.default.svc.cluster.local',
    ];

    /**
     * Reserved IPv4 blocks that must never be reached from the app.
     * IPv6 handled separately with FILTER_FLAG_NO_PRIV_RANGE.
     */
    private const RESERVED_V4 = [
        ['0.0.0.0',      8],   // this network
        ['10.0.0.0',     8],   // private
        ['100.64.0.0',   10],  // shared address space (CGN)
        ['127.0.0.0',    8],   // loopback
        ['169.254.0.0',  16],  // link-local + metadata
        ['172.16.0.0',   12],  // private
        ['192.0.0.0',    24],  // IETF
        ['192.0.2.0',    24],  // TEST-NET-1
        ['192.168.0.0',  16],  // private
        ['198.18.0.0',   15],  // benchmarking
        ['198.51.100.0', 24],  // TEST-NET-2
        ['203.0.113.0',  24],  // TEST-NET-3
        ['224.0.0.0',    4],   // multicast
        ['240.0.0.0',    4],   // reserved
        ['255.255.255.255', 32], // broadcast
    ];

    public function validate(string $url, array $opts = []): UrlPolicyResult
    {
        $url = trim($url);
        if ($url === '') {
            return UrlPolicyResult::deny($url, 'empty', 'URL is empty.');
        }
        if (strlen($url) > 2048) {
            return UrlPolicyResult::deny($url, 'too_long', 'URL exceeds 2048 chars.');
        }

        $parts = parse_url($url);
        if ($parts === false || $parts === null || empty($parts['scheme']) || empty($parts['host'])) {
            return UrlPolicyResult::deny($url, 'malformed', 'URL is malformed.');
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return UrlPolicyResult::deny($url, 'scheme', "Scheme '$scheme' is not permitted.");
        }
        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            return UrlPolicyResult::deny($url, 'userinfo', 'URLs with embedded credentials are not permitted.');
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            return UrlPolicyResult::deny($url, 'host_empty', 'Host is empty.');
        }

        if (in_array($host, self::METADATA_HOSTS, true)) {
            return UrlPolicyResult::deny($url, 'metadata_host', 'Cloud metadata endpoints are not permitted.', $host);
        }

        $envAllowed = array_map(
            'strtolower',
            array_filter(array_map('trim', explode(',', (string) env('URL_POLICY_ALLOWED_HOSTS', ''))))
        );
        $optAllowed = array_map('strtolower', $opts['allowedHosts'] ?? []);
        $allowedHosts = array_unique(array_merge($envAllowed, $optAllowed));

        if ($this->hostMatchesAllowList($host, $allowedHosts)) {
            return UrlPolicyResult::allow($url);
        }

        $ips = $this->resolveHost($host);
        if ($ips === []) {
            return UrlPolicyResult::deny($url, 'unresolvable', "Host '$host' could not be resolved.", $host);
        }
        foreach ($ips as $ip) {
            if ($this->isReservedIp($ip)) {
                return UrlPolicyResult::deny($url, 'reserved_ip', "Host '$host' resolves to reserved IP $ip.", $host);
            }
        }

        return UrlPolicyResult::allow($url);
    }

    /**
     * Check if host matches an allow-list entry. Entries starting with a
     * leading dot match any subdomain (e.g. ".aicountly.org" matches
     * "engage.aicountly.org").
     */
    private function hostMatchesAllowList(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            if ($allowed === '') {
                continue;
            }
            if (str_starts_with($allowed, '.')) {
                $suffix = substr($allowed, 1);
                if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                    return true;
                }
                continue;
            }
            if ($host === $allowed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return every IP a hostname resolves to. IP literals are returned
     * as-is without a DNS lookup.
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $a = @dns_get_record($host, DNS_A);
        if (is_array($a)) {
            foreach ($a as $rec) {
                if (! empty($rec['ip'])) {
                    $ips[] = $rec['ip'];
                }
            }
        }
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (! empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $records = @gethostbynamel($host);
            if (is_array($records)) {
                $ips = array_merge($ips, $records);
            }
        }
        return array_values(array_unique($ips));
    }

    private function isReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isReservedIpv6($ip);
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        foreach (self::RESERVED_V4 as [$block, $bits]) {
            $blockLong = ip2long($block);
            if ($blockLong === false) {
                continue;
            }
            $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
            if ((($long & $mask) & 0xFFFFFFFF) === (($blockLong & $mask) & 0xFFFFFFFF)) {
                return true;
            }
        }
        return false;
    }

    private function isReservedIpv6(string $ip): bool
    {
        $lower = strtolower($ip);
        if ($lower === '::1' || $lower === '::' || $lower === '::ffff:0:0' || str_starts_with($lower, '::ffff:127.')) {
            return true;
        }
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
