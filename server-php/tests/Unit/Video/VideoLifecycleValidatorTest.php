<?php

declare(strict_types=1);

namespace Tests\Unit\Video;

use App\Libraries\Video\VideoLifecycleValidator;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoLifecycleValidatorTest extends CIUnitTestCase
{
    private VideoLifecycleValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new VideoLifecycleValidator();
    }

    public function testValidIdeaTransitionReturnsTrue(): void
    {
        $this->assertTrue($this->validator->canTransitionIdea('draft', 'ready'));
    }

    public function testInvalidIdeaTransitionReturnsFalse(): void
    {
        $this->assertFalse($this->validator->canTransitionIdea('draft', 'converted'));
    }

    public function testUnknownIdeaStatusReturnsFalse(): void
    {
        $this->assertFalse($this->validator->canTransitionIdea('nonexistent', 'ready'));
    }

    public function testValidProjectTransitionReturnsTrue(): void
    {
        $this->assertTrue($this->validator->canTransitionProject('draft', 'script_generating'));
    }

    public function testInvalidProjectTransitionReturnsFalse(): void
    {
        $this->assertFalse($this->validator->canTransitionProject('draft', 'rendering'));
    }

    public function testValidScriptTransitionReturnsTrue(): void
    {
        $this->assertTrue($this->validator->canTransitionScript('draft', 'in_review'));
    }

    public function testInvalidScriptTransitionReturnsFalse(): void
    {
        $this->assertFalse($this->validator->canTransitionScript('approved', 'draft'));
    }

    public function testValidRenderJobTransitionReturnsTrue(): void
    {
        $this->assertTrue($this->validator->canTransitionRenderJob('queued', 'reserved'));
    }

    public function testInvalidRenderJobTransitionReturnsFalse(): void
    {
        $this->assertFalse($this->validator->canTransitionRenderJob('rendered', 'queued'));
    }

    public function testAssertIdeaTransitionThrowsOnInvalidTransition(): void
    {
        $this->expectException(\LogicException::class);
        $this->validator->assertIdeaTransition('converted', 'draft');
    }

    public function testAssertProjectTransitionThrowsOnInvalidTransition(): void
    {
        $this->expectException(\LogicException::class);
        $this->validator->assertProjectTransition('cancelled', 'draft');
    }

    public function testAssertScriptTransitionThrowsOnInvalidTransition(): void
    {
        $this->expectException(\LogicException::class);
        $this->validator->assertScriptTransition('rejected', 'in_review');
    }

    public function testAssertRenderJobTransitionThrowsOnInvalidTransition(): void
    {
        $this->expectException(\LogicException::class);
        $this->validator->assertRenderJobTransition('dead_letter', 'queued');
    }
}
