<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * Estimates Flesch-Kincaid readability score from plain text.
 * Flags content that is significantly too complex or too simple.
 */
class ReadabilityScoreValidator implements ContentValidatorInterface
{
    public function getType(): string { return 'readability_score'; }
    public function isAiAssisted(): bool { return false; }

    public function validate(array $content, array $context): array
    {
        $text = strip_tags($content['body_html'] ?? $content['body_plain_text'] ?? '');
        $text = trim($text);

        if (strlen($text) < 100) {
            return [new ValidationFinding('readability_score', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Text too short', 'Not enough text to score.')];
        }

        $score = $this->fleschKincaid($text);

        if ($score < 20) {
            return [new ValidationFinding('readability_score', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Content too complex', "Readability score: {$score}. Content may be too complex for most readers.", null, ['score' => $score])];
        }

        if ($score > 90) {
            return [new ValidationFinding('readability_score', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_INFO, 'Content very simple', "Readability score: {$score}. Content may be too simplistic for professional audiences.", null, ['score' => $score])];
        }

        return [new ValidationFinding('readability_score', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Readability score acceptable', "Score: {$score}")];
    }

    private function fleschKincaid(string $text): float
    {
        $sentences = max(1, preg_match_all('/[.!?]+/', $text, $m));
        $words     = max(1, preg_match_all('/\b\w+\b/', $text, $m));
        $syllables = max($words, $this->countSyllables($text));

        return round(206.835 - 1.015 * ($words / $sentences) - 84.6 * ($syllables / $words), 1);
    }

    private function countSyllables(string $text): int
    {
        $words = preg_split('/\s+/', strtolower($text));
        $count = 0;
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z]/', '', $word);
            if (strlen($word) <= 3) { $count += 1; continue; }
            $word = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word);
            $word = preg_replace('/^y/', '', $word);
            $count += max(1, preg_match_all('/[aeiouy]{1,2}/', $word, $m));
        }
        return $count;
    }
}
