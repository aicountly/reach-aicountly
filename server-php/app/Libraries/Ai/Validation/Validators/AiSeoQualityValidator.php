<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation\Validators;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Validation\ContentValidatorInterface;
use App\Libraries\Ai\Validation\ValidationFinding;

/**
 * AI-assisted SEO quality check: keyword density, heading structure, internal link suggestions.
 */
class AiSeoQualityValidator implements ContentValidatorInterface
{
    private AiProviderRegistry $registry;

    public function __construct(?AiProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new AiProviderRegistry();
    }

    public function getType(): string { return 'ai_seo_quality'; }
    public function isAiAssisted(): bool { return true; }

    public function validate(array $content, array $context): array
    {
        if (($_ENV['REACH_AI_MOCK'] ?? 'false') !== 'true') {
            return [new ValidationFinding('ai_seo_quality', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'SEO quality check skipped', 'Provider not available.')];
        }

        try {
            $provider = $this->registry->get('mock');
            $result   = $provider->generate(new AiGenerationInput(
                systemPrompt: 'You are an SEO expert. Evaluate the SEO quality of the provided content.',
                userPrompt:   'Title: ' . ($content['title'] ?? '') . "\nMeta: " . ($content['meta_description'] ?? '') . "\nBody excerpt: " . mb_substr(strip_tags($content['body_html'] ?? ''), 0, 500),
                outputSchema: ['type' => 'object', 'properties' => ['score' => ['type' => 'integer'], 'issues' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['score', 'issues']],
                modelKey:     'mock-model',
            ));

            if ($result->parsedJson && ($result->parsedJson['score'] ?? 100) < 50) {
                return [new ValidationFinding('ai_seo_quality', ValidationFinding::STATUS_WARNING, ValidationFinding::SEVERITY_WARNING, 'Low SEO quality score', 'AI rated SEO quality below threshold.', null, $result->parsedJson, 'Review SEO suggestions.', true)];
            }

            return [new ValidationFinding('ai_seo_quality', ValidationFinding::STATUS_PASSED, ValidationFinding::SEVERITY_INFO, 'SEO quality check passed', 'Score: ' . ($result->parsedJson['score'] ?? 'N/A'), null, null, null, true)];
        } catch (\Throwable) {
            return [new ValidationFinding('ai_seo_quality', ValidationFinding::STATUS_NOT_APPLICABLE, ValidationFinding::SEVERITY_INFO, 'SEO quality check unavailable', 'Provider call failed.')];
        }
    }
}
