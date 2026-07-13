<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

class WordCountValidator implements ContentValidatorInterface
{
    private const MINIMUMS = [
        'blog_post'          => 400,
        'landing_page'       => 200,
        'whitepaper'         => 1000,
        'case_study'         => 500,
        'knowledge_base'     => 300,
        'product_description' => 100,
    ];

    public function getType(): string { return 'word_count'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $contentType = $context['content_type'] ?? '';
        $minimum     = self::MINIMUMS[$contentType] ?? null;

        if ($minimum === null) {
            return [new ValidationFinding('word_count', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Word count not required', 'No minimum set for ' . $contentType)];
        }

        $text  = strip_tags($content['body_html'] ?? $content['body_plain_text'] ?? '');
        $words = str_word_count(trim($text));

        if ($words < $minimum) {
            return [new ValidationFinding('word_count', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Word count below minimum', "{$words} words; minimum for {$contentType} is {$minimum}.", 'body_html', ['word_count' => $words, 'minimum' => $minimum])];
        }

        return [new ValidationFinding('word_count', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Word count acceptable', "{$words} words.")];
    }
}
