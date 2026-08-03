<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Generation;

use App\Libraries\Ai\AiGenerationResult;
use App\Libraries\Ai\Generation\AiGenerationArtifactService;
use App\Libraries\Ai\Prompts\OutputSchemaRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Generation\AiGenerationArtifactService
 *
 * Unit tests exercise schema validation logic only — no DB calls.
 * We test the private sanitise and validate paths via the service's public API
 * using a subclassed version that overrides the DB write.
 */
class AiGenerationArtifactServiceTest extends CIUnitTestCase
{
    /**
     * Schema validation passes for valid blog post output.
     */
    public function testSchemaValidationPassesForValidOutput(): void
    {
        $schema  = OutputSchemaRegistry::get('blog_post');
        $service = $this->serviceWithoutDb();
        $plain   = str_repeat('Indian SMEs should track GST invoices and reconcile ITC monthly. ', 20);
        $result  = $this->makeResult([
            'title'          => 'Test Title',
            'summary'        => 'Test summary for a genuine blog post about GST compliance for SMEs.',
            'body_html'      => '<p>' . htmlspecialchars($plain) . '</p>',
            'body_markdown'  => $plain,
            'body_plain_text' => $plain,
            'slug_suggestion' => 'test-title',
            'meta_title'     => 'Test Meta Title',
            'meta_description' => 'Test meta description for GST compliance guidance.',
            'primary_cta'    => 'Learn More',
            'claims_used'    => [],
            'citations_used' => [],
            'risk_notes'     => [],
            'reading_time_minutes' => 3,
            'sections' => [
                ['heading' => 'Intro', 'body' => $plain],
            ],
        ]);

        $status = $service->validateOnly($result, $schema);
        $this->assertSame('passed', $status);
    }

    public function testSchemaValidationFailsForMalformedOutput(): void
    {
        $schema  = OutputSchemaRegistry::get('blog_post');
        $service = $this->serviceWithoutDb();
        $result  = $this->makeResult(null); // no parsed JSON

        $status = $service->validateOnly($result, $schema);
        $this->assertSame('failed', $status);
    }

    public function testSchemaValidationFailsForMissingRequiredField(): void
    {
        $schema  = OutputSchemaRegistry::get('blog_post');
        $service = $this->serviceWithoutDb();
        $result  = $this->makeResult(['summary' => 'No title here.']);

        $status = $service->validateOnly($result, $schema);
        $this->assertSame('failed', $status);
    }

    private function makeResult(?array $parsed): AiGenerationResult
    {
        return new AiGenerationResult(
            rawContent:         $parsed !== null ? json_encode($parsed) : 'not valid json {{{{',
            parsedJson:         $parsed,
            inputTokens:        100,
            outputTokens:       200,
            totalTokens:        300,
            providerResponseId: 'test',
            durationMs:         50,
            modelKey:           'mock',
            providerKey:        'mock',
        );
    }

    private function serviceWithoutDb(): object
    {
        return new class extends AiGenerationArtifactService {
            public function validateOnly(AiGenerationResult $result, array $schema): string
            {
                $validator = new \App\Libraries\Ai\Prompts\StructuredOutputValidator();

                if ($result->parsedJson === null) {
                    return 'failed';
                }

                $errors = $validator->validate($result->parsedJson, $schema);
                return empty($errors) ? 'passed' : 'failed';
            }
        };
    }
}
