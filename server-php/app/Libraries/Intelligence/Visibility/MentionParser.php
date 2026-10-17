<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

/**
 * Reads an assistant's answer for: is AICOUNTLY mentioned, where does it sit
 * in any list of recommendations, which tracked competitors are mentioned,
 * and which URLs were cited. Pure — no I/O — so every rule is unit-tested.
 *
 * Matching rules
 *  - Every alias matches as a whole word (letters/digits on either side stop
 *    a match), case-insensitively, with any run of whitespace standing in
 *    for a space.
 *  - An alias that is a single ordinary English word ("Tally", "Busy",
 *    "Swipe", "Sage", "Wave", …) must start with a capital letter, so "keep a
 *    tally" or "a busy shop" is not counted as a competitor mention.
 *
 * Rank rules
 *  - A list is a run of numbered items ("1." / "1)" / "### 2. …"), top-level
 *    bullets, or the rows of a markdown table. A numbered list restarts when
 *    an item is numbered 1 again; a bullet list ends at an unindented
 *    paragraph.
 *  - An entity's rank is its position in the first list where it is the
 *    item's subject — the bold lead, or the text before the first ":" / " - "
 *    / "(" (a table row's first cell). Named only in an item's description
 *    ("a good Tally alternative") gives no rank. Numbered lists use the
 *    number as written.
 *  - Markdown headings count as list items only when they name a tracked
 *    entity, so section titles ("Key features") do not push ranks down.
 */
final class MentionParser
{
    public const VERSION = 'h1.0';

    /** Single-word aliases that are also everyday words: they must be capitalised to count. */
    private const COMMON_WORDS = [
        'tally', 'busy', 'swipe', 'sage', 'wave', 'marg', 'clear', 'vyapar', 'fresh',
        'wise', 'bench', 'pilot', 'zip', 'ease', 'easy', 'simple', 'khata',
    ];

    /**
     * @param list<string>                                                    $brandAliases
     * @param list<array{id?: int|null, name: string, aliases?: list<string>}> $competitors
     * @param list<array{url: string, title?: string}>                         $providerCitations
     * @param list<string>                                                    $brandDomains   e.g. ['aicountly.com']
     * @return array{
     *   brand: array{name: string, mentioned: bool, rank: ?int, matched: ?string, excerpt: ?string, in_question: bool},
     *   competitors: list<array{id: ?int, name: string, mentioned: bool, rank: ?int, matched: ?string, excerpt: ?string, in_question: bool}>,
     *   list_detected: bool,
     *   ranked: list<array{name: string, rank: int, is_brand: bool}>,
     *   citations: list<array{url: string, domain: string, title: string, source: string, order: int}>,
     *   brand_cited: bool,
     *   parser_version: string
     * }
     */
    public function parse(
        string $text,
        array $brandAliases,
        array $competitors,
        array $providerCitations = [],
        string $question = '',
        array $brandDomains = ['aicountly.com'],
    ): array {
        $brandAliases = $this->cleanAliases($brandAliases);
        $brandName    = $brandAliases[0] ?? 'AICOUNTLY';
        $items        = $this->listItems($text);

        $entities = [['key' => 'brand', 'id' => null, 'name' => $brandName, 'aliases' => $brandAliases, 'is_brand' => true]];
        foreach ($competitors as $i => $c) {
            $name = trim((string) ($c['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $entities[] = [
                'key'      => 'c' . $i,
                'id'       => isset($c['id']) ? (int) $c['id'] : null,
                'name'     => $name,
                'aliases'  => $this->cleanAliases(array_merge([$name], (array) ($c['aliases'] ?? []))),
                'is_brand' => false,
            ];
        }

        // Headings only count as items when they name a tracked entity.
        $items = array_values(array_filter($items, function (array $item) use ($entities): bool {
            if ($item['kind'] !== 'heading') {
                return true;
            }
            foreach ($entities as $e) {
                if ($this->firstMatch($item['head'], $e['aliases']) !== null) {
                    return true;
                }
            }
            return false;
        }));
        $items = $this->renumber($items);

        $results = [];
        $ranked  = [];
        foreach ($entities as $e) {
            $match = $this->firstMatch($text, $e['aliases']);
            $rank  = null;
            $list  = null;
            if ($match !== null) {
                foreach ($items as $item) {
                    if ($this->firstMatch($item['head'], $e['aliases']) !== null) {
                        $rank = $item['rank'];
                        $list = $item['list'];
                        break;
                    }
                }
            }
            $row = [
                'id'          => $e['id'],
                'name'        => $e['name'],
                'mentioned'   => $match !== null,
                'rank'        => $rank,
                'matched'     => $match[1] ?? null,
                'excerpt'     => $match !== null ? $this->excerpt($text, $match[0]) : null,
                'in_question' => $question !== '' && $this->firstMatch($question, $e['aliases']) !== null,
                '_pos'        => $match[0] ?? PHP_INT_MAX,
            ];
            if ($rank !== null) {
                $ranked[] = ['name' => $e['name'], 'rank' => $rank, 'is_brand' => $e['is_brand'], '_list' => $list];
            }
            $results[$e['key']] = $row;
        }

        $brand = $results['brand'];
        unset($results['brand'], $brand['_pos'], $brand['id']);

        $competitorRows = array_values(array_filter($results, static fn (array $r): bool => $r['mentioned']));
        usort($competitorRows, static fn (array $a, array $b): int => $a['_pos'] <=> $b['_pos']);
        $competitorRows = array_map(static function (array $r): array {
            unset($r['_pos']);
            return $r;
        }, $competitorRows);

        usort($ranked, static fn (array $a, array $b): int => [$a['_list'], $a['rank']] <=> [$b['_list'], $b['rank']]);
        $ranked = array_map(static fn (array $r): array => ['name' => $r['name'], 'rank' => $r['rank'], 'is_brand' => $r['is_brand']], $ranked);

        $citations  = $this->citations($text, $providerCitations);
        $brandCited = false;
        foreach ($citations as $c) {
            foreach ($brandDomains as $d) {
                $d = strtolower(trim($d));
                if ($d !== '' && ($c['domain'] === $d || str_ends_with($c['domain'], '.' . $d))) {
                    $brandCited = true;
                }
            }
        }

        return [
            'brand'          => $brand,
            'competitors'    => $competitorRows,
            'list_detected'  => $ranked !== [],
            'ranked'         => $ranked,
            'citations'      => $citations,
            'brand_cited'    => $brandCited,
            'parser_version' => self::VERSION,
        ];
    }

    /**
     * First occurrence of any alias: [byte offset, matched text] or null.
     *
     * @param list<string> $aliases
     * @return array{0: int, 1: string}|null
     */
    public function firstMatch(string $haystack, array $aliases): ?array
    {
        $best = null;
        foreach ($aliases as $alias) {
            if (preg_match($this->pattern($alias), $haystack, $m, PREG_OFFSET_CAPTURE) === 1) {
                $offset = (int) $m[0][1];
                if ($best === null || $offset < $best[0]) {
                    $best = [$offset, (string) $m[0][0]];
                }
            }
        }

        return $best;
    }

    private function pattern(string $alias): string
    {
        $parts  = preg_split('/\s+/u', trim($alias)) ?: [];
        $quoted = implode('\s+', array_map(static fn (string $p): string => preg_quote($p, '/'), $parts));
        $strict = count($parts) === 1
            && preg_match('/^\p{L}+$/u', $alias) === 1
            && in_array(mb_strtolower($alias), self::COMMON_WORDS, true);

        if ($strict) {
            // Capitalised or all-caps only: "Tally", "TALLY" — never "tally".
            $first = mb_substr($alias, 0, 1);
            $rest  = mb_substr($alias, 1);
            $body  = preg_quote(mb_strtoupper($first), '/') . '(?:' . preg_quote(mb_strtolower($rest), '/') . '|' . preg_quote(mb_strtoupper($rest), '/') . ')';
            return '/(?<![\p{L}\p{N}_])' . $body . '(?![\p{L}\p{N}_])/u';
        }

        return '/(?<![\p{L}\p{N}_])' . $quoted . '(?![\p{L}\p{N}_])/iu';
    }

    /**
     * @param list<mixed> $aliases
     * @return list<string>
     */
    private function cleanAliases(array $aliases): array
    {
        $out  = [];
        $seen = [];
        foreach ($aliases as $a) {
            $a = trim((string) $a);
            if (mb_strlen($a) < 2 || isset($seen[mb_strtolower($a)])) {
                continue;
            }
            $seen[mb_strtolower($a)] = true;
            $out[]                   = $a;
        }

        return $out;
    }

    /**
     * @return list<array{kind: string, number: ?int, head: string, list: int, ordinal: int}>
     */
    private function listItems(string $text): array
    {
        $lines   = preg_split('/\R/u', $text) ?: [];
        $items   = [];
        $list    = 0;
        $kind    = null;   // kind of the current list
        $lastNum = 0;
        $gap     = false;  // an unindented paragraph since the last bullet
        $tableRow = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim(str_replace("\t", '    ', $line)));

            // Markdown table rows.
            if (preg_match('/^\s*\|(.+)\|\s*$/u', $line, $m) === 1) {
                if (preg_match('/^[\s|:\-]+$/', $m[1]) === 1) {
                    continue; // separator row
                }
                if ($kind !== 'table') {
                    $list++;
                    $kind     = 'table';
                    $tableRow = 0;
                    continue; // header row
                }
                $tableRow++;
                $cells   = explode('|', $m[1]);
                $items[] = ['kind' => 'table', 'number' => $tableRow, 'head' => $this->stripMarkup((string) $cells[0]), 'list' => $list, 'ordinal' => 0];
                continue;
            }

            if ($indent <= 3 && preg_match('/^\s*(?:#{1,6}\s*)?(?:\*\*|__)?\s*(\d{1,2})[.)](?:\*\*|__)?\s+(.+)$/u', $line, $m) === 1) {
                $num = (int) $m[1];
                if ($kind !== 'numbered' || $num <= $lastNum) {
                    $list++;
                    $kind = 'numbered';
                }
                $lastNum = $num;
                $items[] = ['kind' => 'numbered', 'number' => $num, 'head' => $this->head($m[2]), 'list' => $list, 'ordinal' => 0];
                $gap     = false;
                continue;
            }

            if ($indent <= 1 && preg_match('/^\s*[-*•+]\s+(.+)$/u', $line, $m) === 1) {
                if ($kind !== 'bullet' || $gap) {
                    $list++;
                    $kind = 'bullet';
                }
                $items[] = ['kind' => 'bullet', 'number' => null, 'head' => $this->head($m[1]), 'list' => $list, 'ordinal' => 0];
                $gap     = false;
                continue;
            }

            if (preg_match('/^\s*#{1,6}\s+(.+)$/u', $line, $m) === 1) {
                if ($kind !== 'heading') {
                    $list++;
                    $kind = 'heading';
                }
                $items[] = ['kind' => 'heading', 'number' => null, 'head' => $this->head($m[1]), 'list' => $list, 'ordinal' => 0];
                continue;
            }

            if ($indent === 0) {
                $gap = true;
                if ($kind === 'table') {
                    $kind = null;
                }
            }
        }

        return $items;
    }

    /**
     * Assign each item its rank: the written number for numbered lists,
     * otherwise its 1-based position within its list.
     *
     * @param list<array{kind: string, number: ?int, head: string, list: int, ordinal: int}> $items
     * @return list<array{kind: string, number: ?int, head: string, list: int, ordinal: int, rank: int}>
     */
    private function renumber(array $items): array
    {
        $counts = [];
        foreach ($items as &$item) {
            $counts[$item['list']] = ($counts[$item['list']] ?? 0) + 1;
            $item['ordinal']       = $counts[$item['list']];
            $item['rank']          = $item['kind'] === 'numbered' && $item['number'] !== null ? $item['number'] : $item['ordinal'];
        }
        unset($item);

        return $items;
    }

    /** The subject of a list item: its bold lead, else the text before ":" / " - " / "(". */
    private function head(string $item): string
    {
        $item = trim($item);
        if (preg_match('/^(?:\*\*|__)(.+?)(?:\*\*|__)\s*(.*)$/u', $item, $m) === 1) {
            // "**Best for GST:** Vyapar — …" — a bold part ending in ":" is a
            // label and the subject follows it; "**Vyapar**: …" is the subject.
            $label = rtrim($m[1]);
            if (str_ends_with($label, ':') && trim($m[2]) !== '') {
                return $this->head($m[2]);
            }
            return $this->stripMarkup($label);
        }
        if (preg_match('/^\[([^\]]+)\]\([^)]*\)/u', $item, $m) === 1) {
            return $this->stripMarkup($m[1]);
        }
        $cut = preg_split('/\s*(?::|\s[-–—]\s|\()/u', $item, 2) ?: [$item];

        return $this->stripMarkup(mb_substr((string) $cut[0], 0, 120));
    }

    private function stripMarkup(string $s): string
    {
        $s = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $s) ?? $s;

        return trim(str_replace(['**', '__', '`', '#'], '', $s));
    }

    private function excerpt(string $text, int $offset): string
    {
        $start  = max(0, $offset - 120);
        $chunk  = substr($text, $start, 360);
        $chunk  = mb_convert_encoding($chunk, 'UTF-8', 'UTF-8');
        $chunk  = trim(preg_replace('/\s+/u', ' ', $chunk) ?? $chunk);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + 360) < strlen($text) ? '…' : '';

        return $prefix . $chunk . $suffix;
    }

    /**
     * Provider-attached sources first, then URLs written in the answer.
     *
     * @param list<array{url: string, title?: string}> $providerCitations
     * @return list<array{url: string, domain: string, title: string, source: string, order: int}>
     */
    private function citations(string $text, array $providerCitations): array
    {
        $out  = [];
        $seen = [];
        $add  = function (string $url, string $title, string $source) use (&$out, &$seen): void {
            $url = rtrim(trim($url), '.,;:!?)]\'"');
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $seen[$url] = true;
            $out[]      = ['url' => $url, 'domain' => self::domainOf($url, $title), 'title' => $title, 'source' => $source, 'order' => count($out) + 1];
        };

        foreach ($providerCitations as $c) {
            $add((string) ($c['url'] ?? ''), (string) ($c['title'] ?? ''), 'provider');
        }
        if (preg_match_all('#https?://[^\s<>()\[\]"\'`]+#iu', $text, $m) > 0) {
            foreach ($m[0] as $url) {
                $add($url, '', 'text');
            }
        }

        return $out;
    }

    /**
     * Host without "www.". Gemini grounding links are Google redirect URLs
     * whose title is the real site's domain, so the title wins for those.
     */
    public static function domainOf(string $url, string $title = ''): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if (str_contains($host, 'vertexaisearch.cloud.google.com') || str_contains($host, 'grounding-api-redirect')) {
            $t = strtolower(trim($title));
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $t) === 1) {
                $host = $t;
            }
        }

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
