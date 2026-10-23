<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Blog\BlogTaxonomy;

/**
 * Scores curated gallery assets against the article they would front.
 *
 * The previous selection was "affinity match, else any least-used asset",
 * which in practice handed GST and TDS articles whatever landscape photo was
 * next in the rotation. Relevance is now scored explicitly and a floor is
 * enforced by the caller, so an unrelated cover is never silently attached.
 *
 * Pure scoring — no database, no side effects.
 */
class CoverRelevanceScorer
{
    /** Weights, highest signal first. */
    private const W_SOURCE_KEY     = 20; // generated for exactly this content-base entry
    private const W_CATEGORY_TAG   = 6;
    private const W_STREAM         = 4;
    private const W_TAG_KEYWORD    = 3;
    private const W_PROMPT_KEYWORD = 1;

    /** Keyword hits from prompt text saturate — one asset cannot win on noise. */
    private const MAX_PROMPT_SCORE = 3;

    /**
     * Default relevance floor; below this an asset is not offered.
     *
     * Measured on TOPICAL signals only — the entry key, a category tag, tag
     * keywords and prompt keywords. A portfolio-stream match ranks between
     * relevant assets but can never make an asset relevant on its own: every
     * routine blog and most uploads carry the stream "marketing", so "stream
     * alone clears the floor" (the old rule, floor 3 < stream weight 4) let any
     * marketing-tagged image front any article. 6 = a category tag, two shared
     * tag keywords, or the entry key.
     */
    public const DEFAULT_MIN_SCORE = 6;

    /**
     * Words that carry no topical signal for finance/compliance content.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'your', 'you', 'are', 'how',
        'what', 'when', 'why', 'who', 'into', 'about', 'guide', 'complete', 'best', 'top',
        'need', 'know', 'explained', 'without', 'common', 'basics', 'simple', 'easy',
        'step', 'steps', 'ultimate', 'business', 'businesses', 'small', 'growing',
        'companies', 'company', 'india', 'indian', 'new', 'using', 'use', 'make', 'guide',
    ];

    /**
     * Topical keywords for an article, ranked-free set of normalised tokens.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public function articleKeywords(string $title, string $category = '', array $tags = [], string $summary = ''): array
    {
        // The category's label ("TDS/TCS", "Income Tax"), so a slug and an
        // old label give the same keywords.
        $category = $category !== '' ? BlogTaxonomy::displayLabel($category) : '';
        $source   = implode(' ', array_filter([$title, $category, implode(' ', $tags), $summary]));

        return $this->tokenize($source);
    }

    /**
     * Score one gallery asset for one article.
     *
     * @param array<string,mixed> $asset      row from reach_media_gallery_assets
     * @param list<string>        $keywords   from articleKeywords()
     * @param string              $articleKey the article's content-base key, when it has one
     * @return array{score:int, topical:int, reasons:list<string>}
     */
    public function score(array $asset, array $keywords, string $category = '', string $stream = '', string $articleKey = ''): array
    {
        $score   = 0;
        $reasons = [];
        $streamPoints = 0;

        $sourceKey = trim((string) ($asset['source_key'] ?? ''));
        if ($articleKey !== '' && $sourceKey !== '' && $sourceKey === trim($articleKey)) {
            $score += self::W_SOURCE_KEY;
            $reasons[] = 'source_key';
        }

        $assetTags   = $this->normalizeTags($asset['category_tags'] ?? []);
        $assetStream = strtolower(trim((string) ($asset['portfolio_stream'] ?? '')));
        $category    = strtolower(trim($category));
        $stream      = strtolower(trim($stream));

        if ($category !== '' && $this->categoryTagged($category, $assetTags)) {
            $score += self::W_CATEGORY_TAG;
            $reasons[] = 'category_tag';
        }

        if ($stream !== '' && $assetStream !== '' && $assetStream === $stream) {
            $score += self::W_STREAM;
            $streamPoints = self::W_STREAM;
            $reasons[] = 'portfolio_stream';
        }

        $tagTokens = $this->tokenize(implode(' ', $assetTags));
        $tagHits   = count(array_intersect($keywords, $tagTokens));
        if ($tagHits > 0) {
            $score += $tagHits * self::W_TAG_KEYWORD;
            $reasons[] = "tag_keywords:{$tagHits}";
        }

        $promptTokens = $this->tokenize((string) ($asset['prompt_used'] ?? ''));
        $promptHits   = count(array_intersect($keywords, $promptTokens));
        if ($promptHits > 0) {
            $score += min($promptHits * self::W_PROMPT_KEYWORD, self::MAX_PROMPT_SCORE);
            $reasons[] = "prompt_keywords:{$promptHits}";
        }

        return ['score' => $score, 'topical' => $score - $streamPoints, 'reasons' => $reasons];
    }

    /**
     * Rank candidates, dropping anything below the (topical) relevance floor. Ties keep
     * the caller's order, which is least-recently-used first, so rotation
     * still holds among equally relevant covers.
     *
     * @param list<array<string,mixed>> $assets
     * @param list<string>              $keywords
     * @return list<array{asset:array<string,mixed>, score:int, reasons:list<string>}>
     */
    public function rank(array $assets, array $keywords, string $category = '', string $stream = '', ?int $minScore = null, string $articleKey = ''): array
    {
        $minScore = $minScore ?? self::minScore();
        $ranked   = [];

        foreach ($assets as $index => $asset) {
            $result = $this->score($asset, $keywords, $category, $stream, $articleKey);
            // The floor is topical: a stream match alone never qualifies.
            if ($result['topical'] < $minScore) {
                continue;
            }
            $ranked[] = [
                'asset'    => $asset,
                'score'    => $result['score'],
                'reasons'  => $result['reasons'],
                '_order'   => $index,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['_order'] <=> $b['_order']);

        return array_map(static function (array $row): array {
            unset($row['_order']);

            return $row;
        }, $ranked);
    }

    /**
     * MEDIA_GALLERY_MIN_RELEVANCE can raise the floor but not lower it below
     * DEFAULT_MIN_SCORE: deployments documented "3" for years, and 3 is the
     * value that let unrelated covers through.
     */
    public static function minScore(): int
    {
        return max(self::DEFAULT_MIN_SCORE, (int) env('MEDIA_GALLERY_MIN_RELEVANCE', self::DEFAULT_MIN_SCORE));
    }

    /**
     * Retired (18 Oct 2026). MEDIA_GALLERY_ALLOW_UNMATCHED_FALLBACK used to let
     * an operator attach "any free cover" when nothing matched. Every cover
     * must now be about its article: on a miss Reach generates one instead,
     * so the switch is ignored and this always answers false.
     */
    public static function allowUnmatchedFallback(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $text   = mb_strtolower(trim($text));
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3 && ! in_array($token, ['gst', 'tds', 'itr', 'roc', 'kyc'], true)) {
                continue;
            }
            if (in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            $out[$this->stem($token)] = true;
        }

        return array_keys($out);
    }

    /** Crude plural folding so "invoices" matches "invoice". */
    private function stem(string $token): string
    {
        if (mb_strlen($token) > 4 && str_ends_with($token, 'ies')) {
            return mb_substr($token, 0, -3) . 'y';
        }
        if (mb_strlen($token) > 3 && str_ends_with($token, 's') && ! str_ends_with($token, 'ss')) {
            return mb_substr($token, 0, -1);
        }

        return $token;
    }

    /**
     * The asset carries the article's category as a tag — under any of its
     * spellings: covers tagged with the old labels ("income tax", "tds",
     * "payroll") still match a post filed under the new slug ("income-tax",
     * "tds-tcs", "payroll-hr"), and the other way round.
     *
     * @param list<string> $assetTags lower-cased
     */
    private function categoryTagged(string $category, array $assetTags): bool
    {
        if (in_array($category, $assetTags, true)) {
            return true;
        }
        $canonical = BlogTaxonomy::canonical($category);
        if ($canonical === null) {
            return false;
        }
        foreach ($assetTags as $tag) {
            if (BlogTaxonomy::canonical($tag) === $canonical) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $tags JSONB column value (string or already-decoded array)
     * @return list<string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?? [];
        }
        if (! is_array($tags)) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag !== '') {
                $out[] = $tag;
            }
        }

        return array_values(array_unique($out));
    }
}
