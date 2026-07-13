<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator
 */
class KnowledgeBaseStructureValidatorTest extends CIUnitTestCase
{
    private KnowledgeBaseStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new KnowledgeBaseStructureValidator();
    }

    public function testValidSequentialStepsPass(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Navigate', 'description' => 'Go to settings'],
            ['step_number' => 2, 'title' => 'Enable', 'description' => 'Turn on feature'],
        ];

        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors);
    }

    public function testEmptyStepsReturnError(): void
    {
        $errors = $this->validator->validateSteps([]);
        $this->assertNotEmpty($errors);
    }

    public function testMissingStepNumberReturnsError(): void
    {
        $steps = [['title' => 'Step', 'description' => 'Do it']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testDuplicateStepNumberReturnsError(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Step', 'description' => 'Do it'],
            ['step_number' => 1, 'title' => 'Duplicate', 'description' => 'Also this'],
        ];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testNonSequentialStepsReturnError(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Step 1', 'description' => 'First'],
            ['step_number' => 3, 'title' => 'Step 3', 'description' => 'Third — gap'],
        ];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
        $this->assertTrue(in_array(true, array_map(fn($e) => str_contains($e, 'Missing step number'), $errors)));
    }

    public function testUnsafeInstructionIsDetected(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Delete all data', 'description' => 'delete all records'],
        ];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testMissingStepTitleReturnsError(): void
    {
        $steps = [['step_number' => 1, 'description' => 'Some desc']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testValidVersionApplicabilityPasses(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'all_current_versions']);
        $this->assertEmpty($errors);
    }

    public function testInvalidVersionApplicabilityTypeReturnsError(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'invalid_type']);
        $this->assertNotEmpty($errors);
    }

    public function testSpecificVersionsRequiresVersionsArray(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'specific_versions']);
        $this->assertNotEmpty($errors);
    }

    public function testPlannedVersionRequiresPreviewLabel(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'planned_version']);
        $this->assertNotEmpty($errors);
    }

    public function testTroubleshootingValidationRequiresSymptomAndResolution(): void
    {
        $entries = [
            ['symptom' => 'Error occurs', 'cause' => 'Missing config', 'resolution' => 'Add the config'],
        ];
        $errors = $this->validator->validateTroubleshooting($entries);
        $this->assertEmpty($errors);
    }

    public function testTroubleshootingMissingResolutionReturnsError(): void
    {
        $entries = [['symptom' => 'Error']];
        $errors  = $this->validator->validateTroubleshooting($entries);
        $this->assertNotEmpty($errors);
    }
}
