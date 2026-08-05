<?php

declare(strict_types=1);

namespace App\Libraries\Media;

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
    private const W_CATEGORY_TAG   = 6;
    private const W_STREAM         = 4;
    private const W_TAG_KEYWORD    = 3;
    private const W_PROMPT_KEYWORD = 1;

    /** Keyword hits from prompt text saturate — one asset cannot win on noise. */
    private const MAX_PROMPT_SCORE = 3;

    /** Default relevance floor; below this an asset is not offered. */
    public const DEFAULT_MIN_SCORE = 3;

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
        $source = implode(' ', array_filter([$title, $category, implode(' ', $tags), $summary]));

        return $this->tokenize($source);
    }

    /**
     * Score one gallery asset for one article.
     *
     * @param array<string,mixed> $asset    row from reach_media_gallery_assets
     * @param list<string>        $keywords from articleKeywords()
     * @return array{score:int, reasons:list<string>}
     */
    public function score(array $asset, array $keywords, string $category = '', string $stream = ''): array
    {
        $score   = 0;
        $reasons = [];

        $assetTags   = $this->normalizeTags($asset['category_tags'] ?? []);
        $assetStream = strtolower(trim((string) ($asset['portfolio_stream'] ?? '')));
        $category    = strtolower(trim($category));
        $stream      = strtolower(trim($stream));

        if ($category !== '' && in_array($category, $assetTags, true)) {
            $score += self::W_CATEGORY_TAG;
            $reasons[] = 'category_tag';
        }

        if ($stream !== '' && $assetStream !== '' && $assetStream === $stream) {
            $score += self::W_STREAM;
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

        return ['score' => $score, 'reasons' => $reasons];
    }

    /**
     * Rank candidates, dropping anything below the relevance floor. Ties keep
     * the caller's order, which is least-recently-used first, so rotation
     * still holds among equally relevant covers.
     *
     * @param list<array<string,mixed>> $assets
     * @param list<string>              $keywords
     * @return list<array{asset:array<string,mixed>, score:int, reasons:list<string>}>
     */
    public function rank(array $assets, array $keywords, string $category = '', string $stream = '', ?int $minScore = null): array
    {
        $minScore = $minScore ?? self::minScore();
        $ranked   = [];

        foreach ($assets as $index => $asset) {
            $result = $this->score($asset, $keywords, $category, $stream);
            if ($result['score'] < $minScore) {
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

    public static function minScore(): int
    {
        return max(1, (int) env('MEDIA_GALLERY_MIN_RELEVANCE', self::DEFAULT_MIN_SCORE));
    }

    /**
     * When no asset clears the floor, an unrelated cover is worse than none:
     * publish continues without a hero unless an operator opts in.
     */
    public static function allowUnmatchedFallback(): bool
    {
        return filter_var(env('MEDIA_GALLERY_ALLOW_UNMATCHED_FALLBACK', false), FILTER_VALIDATE_BOOL);
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
