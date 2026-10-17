<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\Visibility\AssistantException;
use App\Libraries\Intelligence\Visibility\AssistantRegistry;
use App\Libraries\Intelligence\Visibility\GeminiAssistant;
use App\Libraries\Intelligence\Visibility\OpenAiAssistant;
use App\Libraries\Intelligence\Visibility\PerplexityAssistant;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The three visibility assistants against a fake HTTP transport: request
 * shape, answer + citation parsing, and honest failures.
 *
 * @covers \App\Libraries\Intelligence\Visibility\GeminiAssistant
 * @covers \App\Libraries\Intelligence\Visibility\OpenAiAssistant
 * @covers \App\Libraries\Intelligence\Visibility\PerplexityAssistant
 * @covers \App\Libraries\Intelligence\Visibility\AssistantRegistry
 * @covers \App\Libraries\Intelligence\Visibility\AssistantHttp
 */
final class VisibilityAssistantsTest extends CIUnitTestCase
{
    /** @var list<array{url: string, headers: list<string>, body: array}> */
    private array $calls = [];

    private function fake(int $status, array|string $body): callable
    {
        return function (string $url, array $headers, array $payload) use ($status, $body): array {
            $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $payload];
            return ['status' => $status, 'body' => is_string($body) ? $body : (string) json_encode($body)];
        };
    }

    public function testGeminiSendsTheQuestionWithSearchGroundingAndParsesSources(): void
    {
        $g = new GeminiAssistant($this->fake(200, [
            'candidates' => [[
                'content' => ['parts' => [['text' => 'thinking…', 'thought' => true], ['text' => '1. **Zoho Books**' . "\n" . '2. **AICOUNTLY**']]],
                'groundingMetadata' => ['groundingChunks' => [['web' => ['uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/x', 'title' => 'zoho.com']]]],
            ]],
            'usageMetadata' => ['totalTokenCount' => 321],
            'modelVersion'  => 'gemini-2.5-flash',
        ]), 'g-key', 'gemini-2.5-flash', true);

        $a = $g->ask('Best GST billing software in India?');

        $this->assertSame("1. **Zoho Books**\n2. **AICOUNTLY**", $a->text);
        $this->assertSame('gemini', $a->provider);
        $this->assertSame(321, $a->tokensUsed);
        $this->assertSame('zoho.com', $a->citations[0]['title']);
        $this->assertStringContainsString('/v1beta/models/gemini-2.5-flash:generateContent?key=g-key', $this->calls[0]['url']);
        $this->assertSame('Best GST billing software in India?', $this->calls[0]['body']['contents'][0]['parts'][0]['text']);
        $this->assertArrayHasKey('google_search', $this->calls[0]['body']['tools'][0]);
    }

    public function testGeminiWithoutGroundingSendsNoTools(): void
    {
        (new GeminiAssistant($this->fake(200, ['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]), 'k', 'm', false))->ask('q');
        $this->assertArrayNotHasKey('tools', $this->calls[0]['body']);
    }

    public function testGeminiErrorsAreRedactedAndEmptyAnswersFail(): void
    {
        $g = new GeminiAssistant($this->fake(403, ['error' => ['message' => 'API key not valid. key=AIzaSyD-secret-123456']]), 'k', 'm', true);
        try {
            $g->ask('q');
            $this->fail('expected AssistantException');
        } catch (AssistantException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringNotContainsString('AIzaSyD-secret', $e->getMessage());
        }

        $this->expectException(AssistantException::class);
        (new GeminiAssistant($this->fake(200, ['candidates' => [['finishReason' => 'SAFETY']]]), 'k', 'm', true))->ask('q');
    }

    public function testOpenAiUsesReasoningParamsForGpt5AndBearerAuth(): void
    {
        $o = new OpenAiAssistant($this->fake(200, [
            'model'   => 'gpt-5-mini-2025-08-07',
            'choices' => [['message' => ['content' => 'Try Vyapar or AICOUNTLY.', 'annotations' => [['type' => 'url_citation', 'url_citation' => ['url' => 'https://aicountly.com/', 'title' => 'AICOUNTLY']]]]]],
            'usage'   => ['total_tokens' => 99],
        ]), 'sk-test', 'gpt-5-mini');

        $a = $o->ask('Free invoicing app India?');

        $this->assertSame('Try Vyapar or AICOUNTLY.', $a->text);
        $this->assertSame('gpt-5-mini-2025-08-07', $a->model);
        $this->assertSame('https://aicountly.com/', $a->citations[0]['url']);
        $this->assertStringEndsWith('/chat/completions', $this->calls[0]['url']);
        $this->assertContains('Authorization: Bearer sk-test', $this->calls[0]['headers']);
        $this->assertSame(6000, $this->calls[0]['body']['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $this->calls[0]['body']);
    }

    public function testOpenAiLegacyModelUsesMaxTokens(): void
    {
        (new OpenAiAssistant($this->fake(200, ['choices' => [['message' => ['content' => 'x']]]]), 'sk', 'gpt-4o-mini'))->ask('q');
        $this->assertSame(1500, $this->calls[0]['body']['max_tokens']);
        $this->assertArrayNotHasKey('reasoning_effort', $this->calls[0]['body']);
    }

    public function testPerplexityMergesSearchResultsAndCitations(): void
    {
        $p = new PerplexityAssistant($this->fake(200, [
            'choices'        => [['message' => ['content' => 'Tally and Zoho Books lead.']]],
            'search_results' => [['url' => 'https://tallysolutions.com/', 'title' => 'Tally']],
            'citations'      => ['https://tallysolutions.com/', 'https://zoho.com/books'],
        ]), 'pplx-key', 'sonar');

        $a = $p->ask('q');
        $this->assertSame(['https://tallysolutions.com/', 'https://zoho.com/books'], array_column($a->citations, 'url'));
        $this->assertSame('sonar', $this->calls[0]['body']['model']);
    }

    public function testUnconfiguredAssistantsAreSkippedWithAReasonAndNeverCalled(): void
    {
        $registry = new AssistantRegistry([
            new GeminiAssistant($this->fake(200, []), 'k', 'gemini-2.5-flash', true),
            new OpenAiAssistant($this->fake(200, []), '', 'gpt-5-mini'),
            new PerplexityAssistant($this->fake(200, []), null, 'sonar'),
        ], ['gemini', 'openai']);

        $status = array_column($registry->status(), null, 'key');
        $this->assertTrue($status['gemini']['will_run']);
        $this->assertFalse($status['openai']['will_run']);
        $this->assertStringContainsString('No OpenAI API key', (string) $status['openai']['reason']);
        $this->assertFalse($status['perplexity']['enabled']);
        $this->assertStringContainsString('Switched off', (string) $status['perplexity']['reason']);
        $this->assertSame(['gemini'], array_map(static fn ($c) => $c->key(), $registry->runnable()));

        try {
            (new OpenAiAssistant($this->fake(200, []), '', 'gpt-5-mini'))->ask('q');
            $this->fail('expected AssistantException');
        } catch (AssistantException) {
            $this->assertSame([], $this->calls);
        }
    }
}
