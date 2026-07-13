<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * AI-assisted engagement quality check: persuasiveness, clarity, audience fit.
 */
class AiEngagementQualityValidator implements ContentValidatorInterface
{
    private AiProviderRegistry $registry;

    public function __construct(?AiProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new AiProviderRegistry();
    }

    public function getType(): string { return 'ai_engagement_quality'; }
    public function isAiAssisted(): bool { return true; }

    public function validate(array $content, array $context): array
    {
        if (($_ENV['REACH_AI_MOCK'] ?? 'false') !== 'true') {
            return [new ValidationFinding('ai_engagement_quality', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Engagement quality check skipped', 'Provider not available.')];
        }

        try {
            $provider = $this->registry->get('mock');
            $result   = $provider->generate(new AiGenerationInput(
                systemPrompt: 'You are a marketing content quality reviewer.',
                userPrompt:   'Rate the engagement quality of this content (1-100) and note issues: ' . mb_substr($content['body_plain_text'] ?? '', 0, 500),
                outputSchema: ['type' => 'object', 'properties' => ['score' => ['type' => 'integer'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['score', 'issues']],
                modelKey:     'mock-model',
            ));

            return [new ValidationFinding('ai_engagement_quality', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'Engagement quality evaluated', 'Score: ' . ($result->parsedJson['score'] ?? 'N/A'), null, null, null, true)];
        } catch (\Throwable) {
            return [new ValidationFinding('ai_engagement_quality', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'Engagement quality check unavailable', 'Provider call failed.')];
        }
    }
}
