<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiGenerationResult;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\AiGenerationInput
 * @covers \App\Libraries\Ai\AiGenerationResult
 */
class AiGenerationInputResultTest extends CIUnitTestCase
{
    public function testInputWithRequestId(): void
    {
        $input = new AiGenerationInput('system', 'user', [], 'model-key');
        $copy  = $input->withRequestId('req-123');

        $this->assertNull($input->requestId);
        $this->assertSame('req-123', $copy->requestId);
        $this->assertSame('system', $copy->systemPrompt);
    }

    public function testResultEstimatedCost(): void
    {
        $result = new AiGenerationResult(
            rawContent:         'some content',
            parsedJson:         null,
            inputTokens:        1000,
            outputTokens:       500,
            totalTokens:        1500,
            providerResponseId: 'test-id',
            durationMs:         100,
            modelKey:           'gpt-4o',
            providerKey:        'openai',
        );

        // $0.01 per 1k input + $0.02 per 1k output
        $cost = $result->estimatedCost(0.01, 0.02);
        $this->assertEqualsWithDelta(0.02, $cost, 0.0001);
    }

    public function testInputDefaults(): void
    {
        $input = new AiGenerationInput('sys', 'usr', [], 'model');
        $this->assertSame(4096, $input->maxOutputTokens);
        $this->assertSame(30, $input->timeoutSeconds);
        $this->assertNull($input->requestId);
        $this->assertSame([], $input->extraParams);
    }
}
