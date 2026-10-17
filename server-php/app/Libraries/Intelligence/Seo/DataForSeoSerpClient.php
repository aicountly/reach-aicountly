<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Seo;

use App\Libraries\Settings\ReachConfig;

/**
 * DataForSEO SERP API — Google organic, Live / Advanced.
 *
 *   POST https://api.dataforseo.com/v3/serp/google/organic/live/advanced
 *   Authorization: Basic base64(login:password)
 *   body: [{"keyword": "...", "location_code": 2356, "language_code": "en", "device": "desktop", "depth": 100}]
 *
 * Live endpoints take one task per request and answer synchronously:
 *
 *   { "status_code": 20000, "status_message": "Ok.", "cost": 0.006,
 *     "tasks": [ { "status_code": 20000, "status_message": "Ok.", "cost": 0.006,
 *                  "result": [ { "keyword": "...", "check_url": "...", "se_results_count": 123,
 *                                "items_count": 100,
 *                                "items": [ { "type": "organic", "rank_group": 1, "rank_absolute": 1,
 *                                             "domain": "www.zoho.com", "url": "https://...", "title": "..." },
 *                                           { "type": "people_also_ask", ... }, ... ] } ] } ] }
 *
 * 20000 is the only success code (top level and per task); anything else
 * (40100 unauthorised, 40200/40210 payment / balance, 40501 bad field …) is
 * surfaced as an exception carrying the provider's message. Only items of
 * type `organic` are kept — ads, featured snippets and PAA blocks are not
 * organic rankings. `rank_absolute` is the position among all SERP elements,
 * which is what a person scanning the page sees.
 *
 * The transport is injectable so tests never touch the network.
 */
final class DataForSeoSerpClient
{
    public const ENDPOINT = 'https://api.dataforseo.com/v3/serp/google/organic/live/advanced';

    /** @var callable(string, string, list<string>, int): array{status:int, body:string, error?:string} */
    private $transport;

    /**
     * @param (callable(string, string, list<string>, int): array{status:int, body:string, error?:string})|null $transport
     */
    public function __construct(
        private readonly ?string $login,
        private readonly ?string $password,
        ?callable $transport = null,
        private readonly int $timeout = 60,
    ) {
        $this->transport = $transport ?? self::curlTransport(...);
    }

    public static function fromConfig(?callable $transport = null): self
    {
        $login = trim((string) ReachConfig::get('seo.dataforseo.login', ''));

        return new self($login !== '' ? $login : null, ReachConfig::secret('seo.dataforseo.password'), $transport);
    }

    public function isConfigured(): bool
    {
        return $this->login !== null && $this->login !== '' && $this->password !== null && $this->password !== '';
    }

    /**
     * @return array{keyword:string, items:list<array{rank_absolute:int, rank_group:?int, domain:string, url:string, title:?string}>, items_count:int, se_results_count:?int, cost:?float, check_url:?string}
     * @throws DataForSeoException
     */
    public function searchOrganic(string $keyword, int $locationCode, string $languageCode, int $depth): array
    {
        if (! $this->isConfigured()) {
            throw new DataForSeoException('DataForSEO credentials are not configured.', 'not_configured');
        }

        $body = json_encode([[
            'keyword'       => $keyword,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
            'device'        => 'desktop',
            'depth'         => max(10, min(700, $depth)),
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = ($this->transport)(self::ENDPOINT, (string) $body, [
            'Authorization: Basic ' . base64_encode($this->login . ':' . $this->password),
            'Content-Type: application/json',
            'Accept: application/json',
        ], $this->timeout);

        if (! empty($response['error'])) {
            throw new DataForSeoException('DataForSEO transport error: ' . $response['error'], 'transport');
        }

        return self::parse($keyword, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
    }

    /**
     * @return array{keyword:string, items:list<array{rank_absolute:int, rank_group:?int, domain:string, url:string, title:?string}>, items_count:int, se_results_count:?int, cost:?float, check_url:?string}
     * @throws DataForSeoException
     */
    public static function parse(string $keyword, int $httpStatus, string $raw): array
    {
        $json = json_decode($raw, true);

        if ($httpStatus === 401 || $httpStatus === 403) {
            $message = is_array($json) ? (string) ($json['status_message'] ?? '') : '';
            throw new DataForSeoException('DataForSEO rejected the credentials (HTTP ' . $httpStatus . ')' . ($message !== '' ? ': ' . $message : '.'), 'auth');
        }
        if (! is_array($json)) {
            throw new DataForSeoException('DataForSEO returned a non-JSON response (HTTP ' . $httpStatus . ').', 'malformed');
        }

        $topCode = (int) ($json['status_code'] ?? 0);
        if ($httpStatus >= 400 || $topCode !== 20000) {
            throw new DataForSeoException(
                'DataForSEO error ' . $topCode . ': ' . (string) ($json['status_message'] ?? 'HTTP ' . $httpStatus),
                self::category($topCode),
            );
        }

        $task = is_array($json['tasks'][0] ?? null) ? $json['tasks'][0] : null;
        if ($task === null) {
            throw new DataForSeoException('DataForSEO response contained no task.', 'malformed');
        }
        $taskCode = (int) ($task['status_code'] ?? 0);
        if ($taskCode !== 20000) {
            throw new DataForSeoException(
                'DataForSEO task error ' . $taskCode . ': ' . (string) ($task['status_message'] ?? 'unknown'),
                self::category($taskCode),
            );
        }

        $result   = is_array($task['result'][0] ?? null) ? $task['result'][0] : [];
        $rawItems = is_array($result['items'] ?? null) ? $result['items'] : [];
        $items    = [];

        foreach ($rawItems as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'organic') {
                continue;
            }
            $rank = isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : 0;
            $url  = (string) ($item['url'] ?? '');
            $host = (string) ($item['domain'] ?? '');
            if ($host === '' && $url !== '') {
                $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
            }
            if ($rank <= 0 || $host === '') {
                continue;
            }
            $items[] = [
                'rank_absolute' => $rank,
                'rank_group'    => isset($item['rank_group']) ? (int) $item['rank_group'] : null,
                'domain'        => self::normaliseHost($host),
                'url'           => $url,
                'title'         => isset($item['title']) ? (string) $item['title'] : null,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['rank_absolute'] <=> $b['rank_absolute']);

        return [
            'keyword'          => (string) ($result['keyword'] ?? $keyword),
            'items'            => $items,
            'items_count'      => count($items),
            'se_results_count' => isset($result['se_results_count']) ? (int) $result['se_results_count'] : null,
            'cost'             => isset($task['cost']) ? (float) $task['cost'] : (isset($json['cost']) ? (float) $json['cost'] : null),
            'check_url'        => isset($result['check_url']) ? (string) $result['check_url'] : null,
        ];
    }

    public static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** True when $host is $domain or one of its subdomains. */
    public static function hostMatches(string $host, string $domain): bool
    {
        $host   = self::normaliseHost($host);
        $domain = self::normaliseHost($domain);

        return $domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain));
    }

    private static function category(int $code): string
    {
        return match (true) {
            $code === 40100, $code === 40101 => 'auth',
            $code === 40202                  => 'rate_limited',
            $code >= 40200 && $code < 40300  => 'billing',
            default                          => 'provider',
        };
    }

    /**
     * @param list<string> $headers
     * @return array{status:int, body:string, error?:string}
     */
    private static function curlTransport(string $url, string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_errno($ch) !== CURLE_OK ? curl_error($ch) : '';
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'error' => $error];
    }
}
