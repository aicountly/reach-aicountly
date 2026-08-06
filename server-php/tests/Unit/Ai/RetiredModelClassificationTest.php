<?php

namespace Tests\Unit\Ai;

use App\Libraries\Ai\AiErrorClassifier;
use App\Libraries\Ai\AiProviderError;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * A model the provider has withdrawn must route around, not kill the request.
 *
 * Google returns "This model models/gemini-2.0-flash is no longer available",
 * which matched no branch in the classifier and landed on CATEGORY_UNKNOWN.
 * Unknown is neither retryable nor in the fallback allow-list, so the
 * orchestrator failed the whole request on the first attempt without trying
 * any other model — while working models sat configured behind it. Every
 * message below is one this system actually received in production.
 */
final class RetiredModelClassificationTest extends CIUnitTestCase
{
    private AiErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new AiErrorClassifier();
    }

    private function classify(string $message): AiProviderError
    {
        return $this->classifier->classify(new RuntimeException($message));
    }

    public function testGoogleRemovedModelIsClassifiedAsRetired(): void
    {
        $error = $this->classify(
            'This model models/gemini-2.0-flash is no longer available. Please update your code to use a newer model.',
        );

        $this->assertSame(AiProviderError::CATEGORY_MODEL_RETIRED, $error->category);
    }

    public function testGoogleRestrictedModelIsClassifiedAsRetired(): void
    {
        $error = $this->classify(
            'This model models/gemini-2.5-pro is no longer available to new users. '
            . 'Please update your code to use a newer model for the latest features and improvements.',
        );

        $this->assertSame(AiProviderError::CATEGORY_MODEL_RETIRED, $error->category);
    }

    public function testOtherProviderPhrasingsAreCovered(): void
    {
        foreach ([
            'The model `gpt-4-vision-preview` does not exist',
            'model not found',
            'The model has been deprecated and is no longer served',
        ] as $message) {
            $this->assertSame(
                AiProviderError::CATEGORY_MODEL_RETIRED,
                $this->classify($message)->category,
                $message,
            );
        }
    }

    public function testARetiredModelReachesTheFallbackChain(): void
    {
        // The actual defect: this returned false, so the orchestrator failed
        // the request instead of hopping to the next configured model.
        $this->assertTrue(
            (new AiProviderError(AiProviderError::CATEGORY_MODEL_RETIRED, 'gone'))->allowsFallback(),
        );
    }

    public function testRetryingTheSameRetiredModelIsStillPointless(): void
    {
        // Fallback yes, retry no — the same model can never come back.
        $this->assertFalse(
            (new AiProviderError(AiProviderError::CATEGORY_MODEL_RETIRED, 'gone'))->isRetryable(),
        );
    }

    public function testCategoriesThatWouldFailIdenticallyElsewhereDoNotFallBack(): void
    {
        // Falling back on these only burns budget on a second identical failure.
        foreach ([
            AiProviderError::CATEGORY_SCHEMA_VALIDATION,
            AiProviderError::CATEGORY_CONTENT_BLOCKED,
            AiProviderError::CATEGORY_CONTEXT_LIMIT,
            AiProviderError::CATEGORY_CANCELLED,
        ] as $category) {
            $this->assertFalse(
                (new AiProviderError($category, 'x'))->allowsFallback(),
                $category . ' must not trigger a fallback',
            );
        }
    }

    public function testAccountLevelFailuresStillFallBack(): void
    {
        // These were already correct and must stay that way: OpenAI quota
        // exhaustion should hop to Gemini rather than fail the request.
        foreach ([
            AiProviderError::CATEGORY_BUDGET_BLOCKED,
            AiProviderError::CATEGORY_AUTHENTICATION,
            AiProviderError::CATEGORY_CONFIGURATION,
            AiProviderError::CATEGORY_PROVIDER_UNAVAIL,
        ] as $category) {
            $this->assertTrue((new AiProviderError($category, 'x'))->allowsFallback(), $category);
        }
    }

    public function testUnrelatedErrorsAreNotMistakenForRetiredModels(): void
    {
        $this->assertSame(
            AiProviderError::CATEGORY_AUTHENTICATION,
            $this->classify('Incorrect API key provided')->category,
        );
        $this->assertSame(
            AiProviderError::CATEGORY_BUDGET_BLOCKED,
            $this->classify('You exceeded your current quota, please check your plan and billing details.')->category,
        );
        $this->assertSame(
            AiProviderError::CATEGORY_RATE_LIMITED,
            $this->classify('Rate limit reached for requests')->category,
        );
    }
}
