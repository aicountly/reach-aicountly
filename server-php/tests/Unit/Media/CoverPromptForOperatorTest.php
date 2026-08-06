<?php

namespace Tests\Unit\Media;

use App\Libraries\Ai\Images\CoverPromptBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The console used to hand the operator the content base's `cover_prompt`
 * verbatim — "Two overlapping document stacks being compared with connecting
 * lines, magnifying glass over a mismatch, flat vector, green-white palette,
 * no text". That is a scene note, not an instruction: no subject, no output
 * format, no count, and nothing that stops a model returning four captioned
 * variations. Copy-paste produced unusable covers.
 */
final class CoverPromptForOperatorTest extends CIUnitTestCase
{
    private const HINT = 'Two overlapping document stacks being compared, magnifying glass over a mismatch';

    public function testTheSceneNoteSurvivesAsTheRequiredConcept(): void
    {
        $prompt = (new CoverPromptBuilder())->buildForOperator(
            'GSTR-2B vs purchase register mismatch',
            self::HINT,
            'marketing',
        );

        $this->assertStringContainsString('Two overlapping document stacks', $prompt, 'The scene note must drive the artwork, not be discarded.');
        $this->assertStringContainsString('GSTR-2B vs purchase register mismatch', $prompt, 'The article is the subject.');
    }

    public function testItStatesTheJobFormatAndCount(): void
    {
        $prompt = (new CoverPromptBuilder())->buildForOperator('Any article title', self::HINT);

        $this->assertStringContainsString('Generate one landscape image', $prompt);
        $this->assertStringContainsString('3:2', $prompt);
        $this->assertStringContainsString('1536x1024', $prompt);
        $this->assertStringContainsString('single image, not a set of variations', $prompt);
    }

    public function testTextIsBannedTwiceOver(): void
    {
        $prompt = (new CoverPromptBuilder())->buildForOperator('Any article title', self::HINT);

        // Covers carry no language, so the same article can be republished or
        // translated without regenerating artwork.
        $this->assertStringContainsString('no text, no words, no letters', $prompt);
        $this->assertStringContainsString('do not add captions', $prompt);
    }

    public function testItWorksWithoutASceneNote(): void
    {
        $prompt = (new CoverPromptBuilder())->buildForOperator('Payroll compliance checklist', null);

        $this->assertStringContainsString('Payroll compliance checklist', $prompt);
        $this->assertStringNotContainsString('Depict:', $prompt, 'An absent hint must not leave a dangling clause.');
    }

    public function testThePipelinePromptIsUnchangedWhenNoHintIsGiven(): void
    {
        // WorkBlockService still calls build() with three arguments; adding the
        // hint parameter must not alter what the paid image providers receive.
        $builder = new CoverPromptBuilder();

        $this->assertSame(
            $builder->build('A title', 'A summary', 'marketing'),
            $builder->build('A title', 'A summary', 'marketing', null),
        );
    }
}
