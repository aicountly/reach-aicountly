<?php

declare(strict_types=1);

namespace App\Libraries\Community\Automation;

use App\Commands\CommunityCategoryCheck;
use App\Libraries\Community\CommunityPublicRecordProvisioner;
use App\Libraries\HtmlSanitizer;
use App\Libraries\SaasProductTaxonomy;
use InvalidArgumentException;

/**
 * Validates and normalises what the community-qa routine submits. Every
 * message is safe to return to the routine verbatim (HTTP 422) and says what
 * to change.
 */
final class CommunityQaPayload
{
    /** The validation gate rejects shorter answers; checked here so the routine hears it at once. */
    public const MIN_ANSWER_CHARS = 50;
    public const MAX_ANSWER_CHARS = 20000;
    public const MAX_SOURCES      = 10;

    /**
     * A routine-written FAQ: the question and the desk's answer.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function faq(array $in, ?HtmlSanitizer $sanitizer = null): array
    {
        $sanitizer ??= new HtmlSanitizer();

        $key = self::externalKey($in['external_key'] ?? null, true);

        $title = self::plain($sanitizer, $in['question_title'] ?? '');
        if (mb_strlen($title) < 10 || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('question_title is required (10–255 characters of plain text).');
        }

        $body = self::plain($sanitizer, $in['question_body'] ?? '');
        if (mb_strlen($body) > 4000) {
            throw new InvalidArgumentException('question_body must be at most 4000 characters.');
        }

        $category = CommunityPublicRecordProvisioner::publicCategoryFor((string) ($in['category_slug'] ?? ''));
        if (! in_array($category, CommunityCategoryCheck::PUBLIC_SLUGS, true)) {
            throw new InvalidArgumentException(
                'category_slug must be one of: ' . implode(', ', CommunityCategoryCheck::PUBLIC_SLUGS) . '.'
            );
        }

        return [
            'external_key'   => $key,
            'question_title' => $title,
            'question_body'  => $body,
            'category_slug'  => $category,
            'product_slug'   => self::product($in['product_slug'] ?? null),
        ] + self::answer($in, $sanitizer);
    }

    /**
     * A desk answer to an existing (member) question.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function memberAnswer(array $in, ?HtmlSanitizer $sanitizer = null): array
    {
        $sanitizer ??= new HtmlSanitizer();

        $questionId = strtolower(trim((string) ($in['question_id'] ?? '')));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $questionId) !== 1) {
            throw new InvalidArgumentException('question_id is required: the question UUID from community-plan open_questions.');
        }

        $key = self::externalKey($in['external_key'] ?? null, false) ?? ('member-answer:' . $questionId);

        return [
            'external_key'  => $key,
            'question_uuid' => $questionId,
        ] + self::answer($in, $sanitizer);
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private static function answer(array $in, HtmlSanitizer $sanitizer): array
    {
        $raw = (string) ($in['answer_html'] ?? '');
        if (trim($raw) === '') {
            throw new InvalidArgumentException('answer_html is required.');
        }
        if ($sanitizer->wouldStripContent($raw)) {
            throw new InvalidArgumentException(
                'answer_html uses markup the site removes. Use only these tags: '
                . implode(', ', config(\Config\ContentSanitization::class)->allowedTags)
                . ' (links: <a href="https://…">), with no attributes other than href/title.'
            );
        }

        $html  = trim($sanitizer->purify($raw));
        $plain = self::plainOf($html);
        if (strlen($html) < self::MIN_ANSWER_CHARS || mb_strlen($plain) < 20) {
            throw new InvalidArgumentException('answer_html is too short: wrap even a one-line answer in <p>…</p> and give at least one full sentence.');
        }
        if (strlen($html) > self::MAX_ANSWER_CHARS) {
            throw new InvalidArgumentException('answer_html must be at most ' . self::MAX_ANSWER_CHARS . ' characters.');
        }

        $short = self::plain($sanitizer, $in['short_answer'] ?? '');
        if (mb_strlen($short) > 300) {
            throw new InvalidArgumentException('short_answer must be at most 300 characters of plain text.');
        }
        if ($short === '') {
            $short = mb_strlen($plain) > 280 ? rtrim(mb_substr($plain, 0, 277)) . '…' : $plain;
        }

        $provenance = is_array($in['provenance'] ?? null) ? $in['provenance'] : [];
        $model      = trim((string) ($provenance['generated_by_model'] ?? ''));
        if ($model === '' || mb_strlen($model) > 120) {
            throw new InvalidArgumentException('provenance.generated_by_model is required (the model that wrote the answer).');
        }
        $runId = trim((string) ($provenance['routine_run_id'] ?? ''));

        return [
            'answer_html' => $html,
            'short_answer' => $short,
            'sources'     => self::sources($in['source_references'] ?? []),
            'provenance'  => [
                'generated_by_model' => $model,
                'routine_run_id'     => $runId !== '' ? mb_substr($runId, 0, 191) : null,
            ],
        ];
    }

    private static function externalKey(mixed $value, bool $required): ?string
    {
        $key = trim((string) $value);
        if ($key === '') {
            if ($required) {
                throw new InvalidArgumentException('external_key is required: a stable id for this question (re-sending it returns the first result).');
            }
            return null;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{2,190}$/', $key) !== 1) {
            throw new InvalidArgumentException('external_key must be 3–191 characters of letters, digits and . _ : -');
        }
        return $key;
    }

    private static function product(mixed $value): ?string
    {
        $slug = strtolower(trim((string) $value));
        if ($slug === '') {
            return null;
        }
        $slug = SaasProductTaxonomy::normalizeProductSlug(str_replace('-', '_', $slug));
        if (! SaasProductTaxonomy::validateProduct($slug)) {
            throw new InvalidArgumentException(
                'product_slug is not an AICOUNTLY product. Use one of: ' . implode(', ', array_keys(SaasProductTaxonomy::products())) . '.'
            );
        }
        return $slug;
    }

    /** @return list<array{source_title: string, source_url: string, coverage_status: string}> */
    private static function sources(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('source_references must be a list of {title, url} objects.');
        }
        if (count($value) > self::MAX_SOURCES) {
            throw new InvalidArgumentException('source_references may hold at most ' . self::MAX_SOURCES . ' entries.');
        }

        $out = [];
        foreach ($value as $i => $source) {
            $url   = is_array($source) ? trim((string) ($source['url'] ?? '')) : trim((string) $source);
            $title = is_array($source) ? trim((string) ($source['title'] ?? '')) : '';
            $parts = parse_url($url);
            if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
                throw new InvalidArgumentException("source_references[{$i}].url must be an http(s) URL.");
            }
            $out[] = [
                'source_title'    => mb_substr($title !== '' ? $title : (string) $parts['host'], 0, 300),
                'source_url'      => mb_substr($url, 0, 2048),
                'coverage_status' => 'cited',
            ];
        }
        return $out;
    }

    private static function plain(HtmlSanitizer $sanitizer, mixed $value): string
    {
        $text = html_entity_decode($sanitizer->purifyText((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[ \t]+/', ' ', $text) ?? $text);
    }

    public static function plainOf(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br />', '<br>', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
