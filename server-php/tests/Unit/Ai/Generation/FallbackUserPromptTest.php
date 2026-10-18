<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Generation;

use App\Libraries\Ai\Generation\AiGenerationOrchestrator;
use PHPUnit\Framework\TestCase;

/**
 * Without an approved prompt template the orchestrator's fallback prompt used
 * to name only the content type ("Generate a blog_post based on the grounding
 * context provided"), never the topic — live posts came out about "the intent
 * draft_generation" instead of their titles. The fallback must carry the topic
 * and forbid writing about the request itself.
 *
 * @covers \App\Libraries\Ai\Generation\AiGenerationOrchestrator
 */
final class FallbackUserPromptTest extends TestCase
{
    private function prompt(array $request): string
    {
        $orchestrator = (new \ReflectionClass(AiGenerationOrchestrator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AiGenerationOrchestrator::class, 'buildUserPrompt');

        return (string) $method->invoke($orchestrator, null, $request);
    }

    public function testTheFallbackNamesTheTopicAndForbidsMetaText(): void
    {
        $prompt = $this->prompt([
            'content_type' => 'blog_post',
            'request_parameters_json' => json_encode([
                'title' => 'TDS Compliance Basics for Growing Companies', 'primary_keyword' => 'tds compliance',
                'questions_to_answer' => ['Who must deduct TDS?'], 'instructions' => 'Write at least 900 words.',
            ]),
        ]);

        $this->assertStringContainsString('Title: TDS Compliance Basics for Growing Companies', $prompt);
        $this->assertStringContainsString('Primary keyword: tds compliance', $prompt);
        $this->assertStringContainsString('Who must deduct TDS?', $prompt);
        $this->assertStringContainsString('Never describe this request, its task, intent, context or pipeline', $prompt);
        $this->assertStringNotContainsString('based on the grounding context provided', $prompt);
        $this->assertStringEndsWith('Write at least 900 words.', $prompt);
    }
}
