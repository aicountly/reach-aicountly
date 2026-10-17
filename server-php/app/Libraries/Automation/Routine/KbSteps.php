<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

/**
 * Normalises knowledge-base steps into the shape the KB readiness gate and
 * the public receiver expect: [{step_number, title, description}], numbered
 * 1..n. Routines send strings, {text}, {title, text} or {title, description}.
 */
final class KbSteps
{
    /**
     * @param array<int, mixed> $steps
     * @return list<array{step_number: int, title: string, description: string}>
     */
    public static function normalize(array $steps): array
    {
        $out = [];
        foreach (array_values($steps) as $step) {
            if (is_string($step)) {
                $step = ['description' => $step];
            }
            if (! is_array($step)) {
                continue;
            }
            $description = trim((string) ($step['description'] ?? $step['text'] ?? $step['body'] ?? ''));
            $title       = trim((string) ($step['title'] ?? ''));
            if ($description === '' && $title === '') {
                continue;
            }
            if ($title === '') {
                $title = self::titleFrom($description);
            }
            if ($description === '') {
                $description = $title;
            }
            $normalised = ['step_number' => count($out) + 1, 'title' => mb_substr($title, 0, 200), 'description' => $description];
            foreach (['tip', 'note', 'expected_result'] as $optional) {
                if (! empty($step[$optional]) && is_string($step[$optional])) {
                    $normalised[$optional] = $step[$optional];
                }
            }
            $out[] = $normalised;
        }

        return $out;
    }

    private static function titleFrom(string $text): string
    {
        $first = preg_split('/(?<=[.!?])\s+/u', $text, 2)[0] ?? $text;
        $first = rtrim($first, '.!? ');

        return mb_strlen($first) > 80 ? rtrim(mb_substr($first, 0, 77)) . '…' : $first;
    }
}
