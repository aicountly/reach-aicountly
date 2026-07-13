<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Prompts;

/**
 * Phase 3 — Renders a prompt template by substituting {{variable}} placeholders.
 *
 * This is a pure string substitution renderer.
 * No eval(), no PHP templating, no user-controlled execution paths.
 */
class PromptRenderer
{
    private PromptVariableValidator $validator;

    public function __construct()
    {
        $this->validator = new PromptVariableValidator();
    }

    /**
     * Render a template with provided variables.
     *
     * @throws \InvalidArgumentException if required variables are missing
     */
    public function render(string $template, array $variables): string
    {
        $missing = $this->validator->findMissing($template, $variables);

        if (! empty($missing)) {
            throw new \InvalidArgumentException(
                'Prompt template is missing required variables: ' . implode(', ', $missing)
            );
        }

        return $this->substitute($template, $variables);
    }

    /**
     * Render without throwing — missing variables become empty strings.
     * Used for preview/partial rendering only.
     */
    public function renderPartial(string $template, array $variables): string
    {
        return $this->substitute($template, $variables);
    }

    private function substitute(string $template, array $variables): string
    {
        $search  = [];
        $replace = [];

        foreach ($variables as $key => $value) {
            $search[]  = '{{' . $key . '}}';
            $replace[] = (string) $value;
        }

        $result = str_replace($search, $replace, $template);

        // Remove any remaining unfilled placeholders
        return preg_replace('/\{\{[a-zA-Z0-9_]+\}\}/', '', $result);
    }
}
