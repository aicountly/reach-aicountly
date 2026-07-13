<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * AI-assisted factual consistency check against grounding context.
 * Uses the mock provider in REACH_AI_MOCK=true environments.
 */
class AiFactualConsistencyValidator implements ContentValidatorInterface
{
    private AiProviderRegistry $registry;

    public function __construct(?AiProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new AiProviderRegistry();
    }

    public function getType(): string { return 'ai_factual_consistency'; }
    public function isAiAssisted(): bool { return true; }

    public function validate(array $content, array $context): array
    {
        if (($_ENV['REACH_AI_MOCK'] ?? 'false') !== 'true') {
            return [new ValidationFinding('ai_factual_consistency', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Factual consistency check skipped', 'Provider not available.')];
        }

        try {
            $provider = $this->registry->get('mock');
            $result   = $provider->generate(new AiGenerationInput(
                systemPrompt: 'You are a factual accuracy reviewer. Check if the content is consistent with the provided grounding context.',
                userPrompt:   'Content: ' . mb_substr($content['body_plain_text'] ?? '', 0, 500) . "\n\nGrounding claims: " . json_encode(array_column($context['grounding']['claims'] ?? [], 'claim_text')),
                outputSchema: ['type' => 'object', 'properties' => ['consistent' => ['type' => 'boolean'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['consistent', 'issues']],
                modelKey:     'mock-model',
            ));

            if ($result->parsedJson && ! ($result->parsedJson['consistent'] ?? true)) {
                return [new ValidationFinding('ai_factual_consistency', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_HIGH, 'Factual inconsistency detected', 'AI identified potential factual issues.', null, $result->parsedJson, 'Review and correct factual claims.', true)];
            }

            return [new ValidationFinding('ai_factual_consistency', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Factual consistency passed', 'Content appears consistent with grounding.', null, null, null, true)];
        } catch (\Throwable) {
            return [new ValidationFinding('ai_factual_consistency', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Factual check unavailable', 'Provider call failed; skipped.')];
        }
    }
}
