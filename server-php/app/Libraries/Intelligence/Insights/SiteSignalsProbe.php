<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

/**
 * Reads the public signals AI assistants use to understand a company, from
 * the live site: llms.txt, schema.org JSON-LD on the home page, robots.txt
 * rules for AI crawlers, the sitemap (comparison and help/docs pages), and
 * whether Wikidata has an entity for the brand.
 *
 * Each check reports what it saw or `null` when it could not look (network
 * error) — an unreachable site is "unknown", never "missing".
 */
final class SiteSignalsProbe
{
    public const AI_CRAWLERS = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'Google-Extended', 'PerplexityBot', 'ClaudeBot', 'anthropic-ai', 'CCBot', 'Applebot-Extended'];

    /** @var callable(string): array{status: int, body: string} */
    private $fetch;

    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch ?? [self::class, 'get'];
    }

    /** @return array<string, mixed> */
    public function probe(string $siteUrl, string $brand = 'Aicountly'): array
    {
        $site = rtrim($siteUrl, '/');

        return [
            'site'       => $site,
            'checked_at' => gmdate('c'),
            'llms_txt'   => $this->llmsTxt($site),
            'schema'     => $this->schema($site),
            'robots'     => $this->robots($site),
            'sitemap'    => $this->sitemap($site),
            'wikidata'   => $this->wikidata($brand),
        ];
    }

    /** @return array{reachable: bool, present: ?bool, bytes?: int, status?: int} */
    private function llmsTxt(string $site): array
    {
        $r = ($this->fetch)($site . '/llms.txt');
        if ($r['status'] === 0) {
            return ['reachable' => false, 'present' => null];
        }
        $body    = trim($r['body']);
        $present = $r['status'] === 200 && $body !== '' && stripos($body, '<html') === false;

        return ['reachable' => true, 'present' => $present, 'status' => $r['status'], 'bytes' => strlen($body)];
    }

    /** @return array<string, mixed> */
    private function schema(string $site): array
    {
        $r = ($this->fetch)($site . '/');
        if ($r['status'] !== 200) {
            return ['reachable' => $r['status'] !== 0, 'status' => $r['status'], 'types' => null];
        }

        $types  = [];
        $org    = null;
        $blocks = 0;
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $r['body'], $m) > 0) {
            foreach ($m[1] as $raw) {
                $json = json_decode(html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5), true);
                if (! is_array($json)) {
                    continue;
                }
                $blocks++;
                $this->collectTypes($json, $types, $org);
            }
        }

        $sameAs = [];
        foreach ((array) ($org['sameAs'] ?? []) as $url) {
            if (is_string($url) && $url !== '') {
                $sameAs[] = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? $url));
            }
        }

        return [
            'reachable'    => true,
            'jsonld_blocks' => $blocks,
            'types'        => array_values(array_unique($types)),
            'organization' => $org === null ? null : [
                'name'          => is_string($org['name'] ?? null) ? $org['name'] : null,
                'has_url'       => ! empty($org['url']),
                'has_logo'      => ! empty($org['logo']),
                'has_address'   => ! empty($org['address']),
                'has_telephone' => ! empty($org['telephone']) || ! empty($org['contactPoint']),
                'same_as_hosts' => array_values(array_unique($sameAs)),
            ],
        ];
    }

    /**
     * @param array<mixed>             $node
     * @param list<string>             $types
     * @param array<string, mixed>|null $org
     */
    private function collectTypes(array $node, array &$types, ?array &$org): void
    {
        if (isset($node['@type'])) {
            foreach ((array) $node['@type'] as $t) {
                if (is_string($t)) {
                    $types[] = $t;
                    if ($org === null && in_array($t, ['Organization', 'Corporation', 'LocalBusiness'], true)) {
                        $org = $node;
                    }
                }
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $this->collectTypes($v, $types, $org);
            }
        }
    }

    /** @return array{reachable: bool, blocked_ai_crawlers: ?list<string>} */
    private function robots(string $site): array
    {
        $r = ($this->fetch)($site . '/robots.txt');
        if ($r['status'] === 0) {
            return ['reachable' => false, 'blocked_ai_crawlers' => null];
        }
        if ($r['status'] !== 200) {
            return ['reachable' => true, 'blocked_ai_crawlers' => []];
        }

        return ['reachable' => true, 'blocked_ai_crawlers' => self::blockedCrawlers($r['body'])];
    }

    /**
     * AI crawlers whose group in robots.txt disallows the whole site ("Disallow: /").
     *
     * @return list<string>
     */
    public static function blockedCrawlers(string $robots): array
    {
        $groups  = [];
        $agents  = [];
        $inRules = false;
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents  = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
                continue;
            }
            $inRules = true;
            foreach ($agents as $a) {
                $groups[$a][] = [$field, $value];
            }
        }

        $blocked = [];
        foreach (self::AI_CRAWLERS as $bot) {
            $rules = $groups[strtolower($bot)] ?? $groups['*'] ?? [];
            $all   = false;
            foreach ($rules as [$field, $value]) {
                if ($field === 'disallow' && $value === '/') {
                    $all = true;
                }
                if ($field === 'allow' && $value === '/') {
                    $all = false;
                }
            }
            if ($all) {
                $blocked[] = $bot;
            }
        }

        return $blocked;
    }

    /** @return array<string, mixed> */
    private function sitemap(string $site): array
    {
        $r = ($this->fetch)($site . '/sitemap.xml');
        if ($r['status'] !== 200) {
            return ['reachable' => $r['status'] !== 0, 'status' => $r['status'], 'urls' => null];
        }

        $urls = $this->locs($r['body']);
        if (stripos($r['body'], '<sitemapindex') !== false) {
            $children = array_slice($urls, 0, 8);
            $urls     = [];
            foreach ($children as $child) {
                $c = ($this->fetch)($child);
                if ($c['status'] === 200) {
                    $urls = array_merge($urls, $this->locs($c['body']));
                }
            }
        }

        $paths = array_map(static fn (string $u): string => strtolower((string) (parse_url($u, PHP_URL_PATH) ?? '')), $urls);
        $count = static fn (string $re): int => count(array_filter($paths, static fn (string $p): bool => preg_match($re, $p) === 1));

        return [
            'reachable'        => true,
            'urls'             => count($urls),
            'comparison_pages' => $count('#(-vs-|/vs/|alternative|compare|comparison)#'),
            'help_docs_pages'  => $count('#/(help|docs?|kb|knowledge[-_]?base|support|guides?|how-to)(/|$)#'),
            'blog_pages'       => $count('#/blog(/|$)#'),
            'pricing_page'     => $count('#/pricing(/|$)#') > 0,
        ];
    }

    /** @return list<string> */
    private function locs(string $xml): array
    {
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m);

        return array_values(array_unique(array_map(static fn (string $u): string => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $m[1] ?? [])));
    }

    /** @return array{reachable: bool, entities: ?list<array{id: string, label: string, description: string}>} */
    private function wikidata(string $brand): array
    {
        $r = ($this->fetch)('https://www.wikidata.org/w/api.php?action=wbsearchentities&format=json&language=en&limit=5&search=' . rawurlencode($brand));
        if ($r['status'] !== 200) {
            return ['reachable' => $r['status'] !== 0, 'entities' => null];
        }
        $json = json_decode($r['body'], true);
        $out  = [];
        foreach ((array) ($json['search'] ?? []) as $hit) {
            $label = (string) ($hit['label'] ?? '');
            if (strcasecmp($label, $brand) === 0) {
                $out[] = ['id' => (string) ($hit['id'] ?? ''), 'label' => $label, 'description' => (string) ($hit['description'] ?? '')];
            }
        }

        return ['reachable' => true, 'entities' => $out];
    }

    /** @return array{status: int, body: string} */
    public static function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'ReachInsights/1.0 (+https://reach.aicountly.org)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE    => 5_000_000,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $raw === false ? 0 : $status, 'body' => $raw === false ? '' : (string) $raw];
    }
}
