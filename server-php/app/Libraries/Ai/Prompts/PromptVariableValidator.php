<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Prompts;

/**
 * Phase 3 — Validates that all required template variables are provided.
 *
 * Detects {{variable}} placeholders in a template string and checks that
 * matching keys exist in the provided variables array.
 */
class PromptVariableValidator
{
    /**
     * Returns a list of missing variable names.
     *
     * @param string $template    Template with {{variable}} placeholders
     * @param array  $variables   Key-value map of supplied variables
     * @return string[]           Names of missing variables
     */
    public function findMissing(string $template, array $variables): array
    {
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $template, $matches);
        $required = array_unique($matches[1] ?? []);

        return array_values(array_filter($required, fn($var) => ! array_key_exists($var, $variables)));
    }

    /**
     * Returns true when all required variables are present.
     */
    public function allPresent(string $template, array $variables): bool
    {
        return empty($this->findMissing($template, $variables));
    }

    /**
     * Returns all placeholder names found in a template.
     */
    public function extractPlaceholders(string $template): array
    {
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $template, $matches);
        return array_unique($matches[1] ?? []);
    }
}
