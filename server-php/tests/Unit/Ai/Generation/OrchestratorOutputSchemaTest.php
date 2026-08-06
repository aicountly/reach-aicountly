<?php

namespace Tests\Unit\Ai\Generation;

use App\Libraries\Ai\Generation\AiGenerationOrchestrator;
use App\Libraries\Ai\Prompts\OutputSchemaRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The structured-output contract must come from the code registry, not from a
 * row in reach_ai_prompt_versions.
 *
 * resolvePromptVersion() matches on task_type alone, so a single stored
 * `draft_generation` prompt supplied the schema for every draft request. In
 * production that row still demanded body_markdown and body_plain_text
 * alongside body_html — a requirement the registry deliberately dropped so
 * providers are not forced to emit three long duplicates and truncate — and
 * 29 GENERATE_DRAFT blocks failed with
 * `schema_validation_failed | ["$.body_html is required", "$.body_markdown is
 * required", "$.body_plain_text is required"]`.
 */
final class OrchestratorOutputSchemaTest extends TestCase
{
    private function resolve(array $request, ?array $promptVersion): array
    {
        $method = new ReflectionMethod(AiGenerationOrchestrator::class, 'resolveOutputSchema');
        $method->setAccessible(true);

        // The constructor wires provider/database services we do not need here.
        $orchestrator = (new \ReflectionClass(AiGenerationOrchestrator::class))->newInstanceWithoutConstructor();

        return $method->invoke($orchestrator, $request, $promptVersion);
    }

    /** A stale stored schema, of the shape that broke production. */
    private function staleStoredSchema(): array
    {
        return [
            'type'     => 'object',
            'required' => ['title', 'summary', 'body_html', 'body_markdown', 'body_plain_text', 'meta_description'],
            'properties' => [
                'title'           => ['type' => 'string'],
                'summary'         => ['type' => 'string', 'minLength' => 1],
                'body_html'       => ['type' => 'string'],
                'body_markdown'   => ['type' => 'string'],
                'body_plain_text' => ['type' => 'string'],
                'meta_description' => ['type' => 'string', 'minLength' => 1],
            ],
        ];
    }

    public function testGovernedContentTypeIgnoresAStaleStoredSchema(): void
    {
        $schema = $this->resolve(
            ['task_type' => 'draft_generation', 'content_type' => 'blog_post'],
            ['output_schema_json' => json_encode($this->staleStoredSchema())],
        );

        $this->assertSame(OutputSchemaRegistry::get('blog_post'), $schema);
        $this->assertNotContains('body_markdown', $schema['required']);
        $this->assertNotContains('body_plain_text', $schema['required']);
        $this->assertContains('body_html', $schema['required']);
    }

    public function testRegistrySchemaCarriesTheMaxLengthTheCoercerTruncatesOn(): void
    {
        $schema = $this->resolve(
            ['task_type' => 'draft_generation', 'content_type' => 'blog_post'],
            ['output_schema_json' => json_encode($this->staleStoredSchema())],
        );

        // "$.summary must be at most 1024 characters" was a live failure; the
        // coercer can only truncate when the schema it validates against
        // actually declares the limit.
        $this->assertSame(1024, $schema['properties']['summary']['maxLength']);
    }

    public function testCommunityAnswerContentTypeResolvesToItsOwnSchema(): void
    {
        $schema = $this->resolve(
            ['task_type' => 'community_answer', 'content_type' => 'community_answer.detailed'],
            null,
        );

        $this->assertContains('answer_body', $schema['required']);
        $this->assertContains('short_answer', $schema['required']);
        $this->assertArrayNotHasKey('body_html', $schema['properties']);
    }

    public function testUngovernedContentTypeStillUsesTheStoredSchema(): void
    {
        $stored = ['type' => 'object', 'required' => ['custom_field'], 'properties' => ['custom_field' => ['type' => 'string']]];

        $schema = $this->resolve(
            ['task_type' => 'draft_generation', 'content_type' => 'some_bespoke_type'],
            ['output_schema_json' => json_encode($stored)],
        );

        $this->assertSame($stored, $schema);
    }

    public function testEmptyStoredSchemaFallsBackToTheRegistry(): void
    {
        $schema = $this->resolve(
            ['task_type' => 'draft_generation', 'content_type' => 'some_bespoke_type'],
            ['output_schema_json' => '{}'],
        );

        $this->assertSame(OutputSchemaRegistry::get('generic'), $schema);
    }
}
