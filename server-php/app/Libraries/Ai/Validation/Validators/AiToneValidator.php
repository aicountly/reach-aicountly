<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * AI-assisted tone and voice consistency validator.
 * Uses the mock provider in REACH_AI_MOCK=true environments.
 * Never calls production AI APIs in automated tests.
 */
class AiToneValidator implements ContentValidatorInterface
{
    private AiProviderRegistry $registry;

    public function __construct(?AiProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new AiProviderRegistry();
    }

    public function getType(): string { return 'ai_tone_check'; }
    public function isAiAssisted(): bool { return true; }

    public function validate(array $content, array $context): array
    {
        // In non-mock environments without a provider, skip gracefully
        if (($_ENV['REACH_AI_MOCK'] ?? 'false') !== 'true') {
            return [new ValidationFinding('ai_tone_check', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'AI tone check skipped', 'Provider not available for AI tone validation.')];
        }

        try {
            $provider = $this->registry->get('mock');
            $input    = new AiGenerationInput(
                systemPrompt: 'You are a tone analysis assistant. Rate the tone as: professional, casual, aggressive, or neutral.',
                userPrompt:   'Analyze the tone of this content: ' . mb_substr($content['body_plain_text'] ?? strip_tags($content['body_html'] ?? ''), 0, 500),
                outputSchema: ['type' => 'object', 'properties' => ['tone' => ['type' => 'string'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['tone', 'issues']],
                modelKey:     'mock-model',
            );

            $result = $provider->generate($input);

            if ($result->parsedJson && ($result->parsedJson['tone'] ?? '') === 'aggressive') {
                return [new ValidationFinding('ai_tone_check', ValidationFinding::STATUS_FAILED, ValidationFinding::SEVERITY_HIGH, 'Aggressive tone detected', 'Content tone was rated as aggressive by AI analysis.', null, $result->parsedJson, 'Revise to use a professional or neutral tone.', true)];
            }

            return [new ValidationFinding('ai_tone_check', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Tone check passed', 'Tone: ' . ($result->parsedJson['tone'] ?? 'unknown'), null, null, null, true)];
        } catch (\Throwable) {
            return [new ValidationFinding('ai_tone_check', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'AI tone check unavailable', 'Provider call failed; skipped.')];
        }
    }
}
