<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Validation;

use App\Libraries\Ai\Validation\Validators\AiEngagementQualityValidator;
use App\Libraries\Ai\Validation\Validators\AiFactualConsistencyValidator;
use App\Libraries\Ai\Validation\Validators\AiSeoQualityValidator;
use App\Libraries\Ai\Validation\Validators\AiToneValidator;
use App\Libraries\Ai\Validation\Validators\BodyMinimumLengthValidator;
use App\Libraries\Ai\Validation\Validators\BrandVoiceValidator;
use App\Libraries\Ai\Validation\Validators\CallToActionPresenceValidator;
use App\Libraries\Ai\Validation\Validators\ClaimsReferencedValidator;
use App\Libraries\Ai\Validation\Validators\ContentPolicyValidator;
use App\Libraries\Ai\Validation\Validators\DuplicateContentValidator;
use App\Libraries\Ai\Validation\Validators\EmailSubjectLineLengthValidator;
use App\Libraries\Ai\Validation\Validators\FeatureAvailabilityValidator;
use App\Libraries\Ai\Validation\Validators\HashtagCountValidator;
use App\Libraries\Ai\Validation\Validators\HtmlSanitizationValidator;
use App\Libraries\Ai\Validation\Validators\MetaDescriptionLengthValidator;
use App\Libraries\Ai\Validation\Validators\ProductClaimAccuracyValidator;
use App\Libraries\Ai\Validation\Validators\ReadabilityScoreValidator;
use App\Libraries\Ai\Validation\Validators\RiskNotesValidator;
use App\Libraries\Ai\Validation\Validators\SlugFormatValidator;
use App\Libraries\Ai\Validation\Validators\SummaryLengthValidator;
use App\Libraries\Ai\Validation\Validators\StructuredOutputValidator;
use App\Libraries\Ai\Validation\Validators\TitleLengthValidator;
use App\Libraries\Ai\Validation\Validators\WordCountValidator;

/**
 * Phase 3 — AI Validation Pipeline.
 *
 * Runs all registered validators against AI-generated content.
 * Links findings to the Phase 2 content validation system.
 * AI must NEVER waive or approve its own findings.
 */
class AiValidationPipelineService
{
    /** @var ContentValidatorInterface[] */
    private array $validators;

    private AiValidationRunService $runService;
    private AiValidationFindingService $findingService;

    public function __construct(?array $validators = null)
    {
        $this->runService     = new AiValidationRunService();
        $this->findingService = new AiValidationFindingService();
        $this->validators     = $validators ?? $this->buildDefaultValidators();
    }

    /**
     * Execute the full validation pipeline for a content item.
     *
     * @param array $contentOutput  The sanitised structured output from the artifact
     * @param array $groundingContext  The grounding context used during generation
     * @param array $meta  ['content_type', 'content_item_id', 'content_version_id', 'generation_request_id']
     * @return array  The completed validation run row
     */
    public function run(array $contentOutput, array $groundingContext, array $meta): array
    {
        $context = array_merge($meta, ['grounding' => $groundingContext]);

        $run = $this->runService->create(
            (int) ($meta['content_item_id'] ?? 0),
            (int) ($meta['content_version_id'] ?? 0),
            isset($meta['generation_request_id']) ? (int) $meta['generation_request_id'] : null,
        );

        $this->runService->markRunning($run['id']);

        $allFindings = [];

        foreach ($this->validators as $validator) {
            try {
                $findings     = $validator->validate($contentOutput, $context);
                $allFindings  = array_merge($allFindings, $findings);
                $this->findingService->storeBatch($run['id'], $findings);
            } catch (\Throwable $e) {
                $this->findingService->store($run['id'], new ValidationFinding(
                    $validator->getType(),
                    ValidationFinding::STATUS_NOT_APPLICABLE,
                    ValidationFinding::SEVERITY_INFO,
                    'Validator error',
                    'Validator ' . $validator->getType() . ' threw an exception: ' . $e->getMessage(),
                ));
            }
        }

        $blocking = count(array_filter($allFindings, fn($f) => $f->isBlocking()));
        $critical = count(array_filter($allFindings, fn($f) => $f->severity === ValidationFinding::SEVERITY_CRITICAL && $f->status === ValidationFinding::STATUS_FAILED));
        $warnings = count(array_filter($allFindings, fn($f) => $f->status === ValidationFinding::STATUS_WARNING));
        $info     = count(array_filter($allFindings, fn($f) => $f->status === ValidationFinding::STATUS_PASSED || $f->status === ValidationFinding::STATUS_NOT_APPLICABLE));

        $this->runService->markCompleted($run['id'], $blocking, $critical, $warnings, $info);

        return $this->runService->findById($run['id']);
    }

    public function addValidator(ContentValidatorInterface $validator): void
    {
        $this->validators[$validator->getType()] = $validator;
    }

    private function buildDefaultValidators(): array
    {
        return [
            new StructuredOutputValidator(),
            new TitleLengthValidator(),
            new SummaryLengthValidator(),
            new MetaDescriptionLengthValidator(),
            new BodyMinimumLengthValidator(),
            new SlugFormatValidator(),
            new ClaimsReferencedValidator(),
            new ProductClaimAccuracyValidator(),
            new BrandVoiceValidator(),
            new ContentPolicyValidator(),
            new RiskNotesValidator(),
            new HtmlSanitizationValidator(),
            new CallToActionPresenceValidator(),
            new DuplicateContentValidator(),
            new HashtagCountValidator(),
            new EmailSubjectLineLengthValidator(),
            new FeatureAvailabilityValidator(),
            new ReadabilityScoreValidator(),
            new WordCountValidator(),
            // AI-assisted (skipped unless REACH_AI_MOCK=true)
            new AiToneValidator(),
            new AiFactualConsistencyValidator(),
            new AiSeoQualityValidator(),
            new AiEngagementQualityValidator(),
        ];
    }
}
