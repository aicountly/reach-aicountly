<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Libraries\Automation\Routine\KbSteps;
use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Automation\Routine\KbSteps
 */
final class KbStepsTest extends CIUnitTestCase
{
    public function testEveryShapeTheRoutineSendsPassesTheReadinessValidator(): void
    {
        $steps = KbSteps::normalize([
            'Open Billing from the launcher.',
            ['text' => 'Choose New invoice. It opens a blank invoice.'],
            ['title' => 'Save', 'description' => 'Press Save.', 'tip' => 'Ctrl+S works too.'],
            ['title' => '', 'text' => ''],
            42,
        ]);

        $this->assertSame([1, 2, 3], array_column($steps, 'step_number'));
        $this->assertSame('Open Billing from the launcher', $steps[0]['title']);
        $this->assertSame('Choose New invoice', $steps[1]['title']);
        $this->assertSame('Ctrl+S works too.', $steps[2]['tip']);
        $this->assertSame([], (new KnowledgeBaseStructureValidator())->validateSteps($steps));
    }
}
