<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Prompts;

use App\Libraries\Ai\Prompts\PromptRenderer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Prompts\PromptRenderer
 */
class PromptRendererTest extends CIUnitTestCase
{
    private PromptRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new PromptRenderer();
    }

    public function testRendersAllVariables(): void
    {
        $template = 'Write a {{content_type}} about {{topic}}.';
        $output   = $this->renderer->render($template, ['content_type' => 'blog post', 'topic' => 'AI']);
        $this->assertSame('Write a blog post about AI.', $output);
    }

    public function testThrowsOnMissingVariable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('topic');
        $this->renderer->render('Write about {{topic}}.', []);
    }

    public function testRenderPartialIgnoresMissing(): void
    {
        $template = '{{present}} and {{missing}}.';
        $output   = $this->renderer->renderPartial($template, ['present' => 'here']);
        $this->assertStringContainsString('here', $output);
        $this->assertStringNotContainsString('{{missing}}', $output);
    }

    public function testRendersNumericValue(): void
    {
        $template = 'Max tokens: {{max}}.';
        $output   = $this->renderer->render($template, ['max' => 4096]);
        $this->assertSame('Max tokens: 4096.', $output);
    }
}
