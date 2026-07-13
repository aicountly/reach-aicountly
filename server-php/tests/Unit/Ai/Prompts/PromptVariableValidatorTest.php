<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Prompts;

use App\Libraries\Ai\Prompts\PromptVariableValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Prompts\PromptVariableValidator
 */
class PromptVariableValidatorTest extends CIUnitTestCase
{
    private PromptVariableValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PromptVariableValidator();
    }

    public function testDetectsNoMissingVariables(): void
    {
        $template = 'Hello {{name}}, you are {{age}} years old.';
        $missing  = $this->validator->findMissing($template, ['name' => 'Alice', 'age' => 30]);
        $this->assertEmpty($missing);
    }

    public function testDetectsMissingVariable(): void
    {
        $template = 'Hello {{name}}, your role is {{role}}.';
        $missing  = $this->validator->findMissing($template, ['name' => 'Bob']);
        $this->assertContains('role', $missing);
    }

    public function testAllPresentReturnsTrueWhenAllPresent(): void
    {
        $template = 'Product: {{product_name}}';
        $this->assertTrue($this->validator->allPresent($template, ['product_name' => 'Aicountly']));
    }

    public function testAllPresentReturnsFalseWhenMissing(): void
    {
        $template = '{{x}} and {{y}}';
        $this->assertFalse($this->validator->allPresent($template, ['x' => 'val']));
    }

    public function testExtractPlaceholders(): void
    {
        $template = 'Use {{a}} and {{b}} and {{a}} again.';
        $placeholders = $this->validator->extractPlaceholders($template);
        $this->assertContains('a', $placeholders);
        $this->assertContains('b', $placeholders);
        $this->assertCount(2, $placeholders);
    }

    public function testHandlesNoPlaceholders(): void
    {
        $template = 'No variables here.';
        $this->assertEmpty($this->validator->extractPlaceholders($template));
        $this->assertEmpty($this->validator->findMissing($template, []));
    }
}
