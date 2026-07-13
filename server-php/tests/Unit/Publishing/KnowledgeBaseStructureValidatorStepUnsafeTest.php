<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for unsafe instruction detection in KB step validator.
 *
 * @covers \App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator
 */
class KnowledgeBaseStructureValidatorStepUnsafeTest extends CIUnitTestCase
{
    private KnowledgeBaseStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new KnowledgeBaseStructureValidator();
    }

    /** @dataProvider safeInstructionsProvider */
    public function testSafeInstructionsAreNotFlagged(string $title, string $description): void
    {
        $steps  = [['step_number' => 1, 'title' => $title, 'description' => $description]];
        $errors = $this->validator->validateSteps($steps);
        $unsafe = array_filter($errors, fn($e) => str_contains($e, 'unsafe'));
        $this->assertEmpty($unsafe, "'{$title}' should not be flagged as unsafe");
    }

    public static function safeInstructionsProvider(): array
    {
        return [
            ['Open Settings', 'Navigate to Settings in the top menu'],
            ['Download Report', 'Click Download to save the report as PDF'],
            ['Enable Feature', 'Toggle the switch to enable the feature'],
            ['Upload Statement', 'Upload your bank statement in CSV format'],
            ['View Analytics', 'Open the Analytics dashboard to view metrics'],
        ];
    }

    /** @dataProvider unsafeInstructionsProvider */
    public function testUnsafeInstructionsAreFlagged(string $title, string $description): void
    {
        $steps  = [['step_number' => 1, 'title' => $title, 'description' => $description]];
        $errors = $this->validator->validateSteps($steps);
        $unsafe = array_filter($errors, fn($e) => str_contains($e, 'unsafe'));
        $this->assertNotEmpty($unsafe, "'{$title}: {$description}' should be flagged as unsafe");
    }

    public static function unsafeInstructionsProvider(): array
    {
        return [
            ['Cleanup', 'delete all users from the database'],
            ['Reset', 'DROP TABLE customers to reset'],
            ['Remove files', 'rm -rf /var/data'],
            ['Shutdown', 'shutdown the server for maintenance'],
        ];
    }

    public function testPasswordPatternIsUnsafe(): void
    {
        $steps = [['step_number' => 1, 'title' => 'Config', 'description' => "set password='admin123'"]];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testEmptyTitleIsAnError(): void
    {
        $steps  = [['step_number' => 1, 'title' => '', 'description' => 'Something']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testEmptyDescriptionIsAnError(): void
    {
        $steps  = [['step_number' => 1, 'title' => 'Do it', 'description' => '']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }
}
