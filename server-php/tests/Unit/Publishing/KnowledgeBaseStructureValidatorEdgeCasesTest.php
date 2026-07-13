<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Edge-case tests for KnowledgeBaseStructureValidator.
 *
 * @covers \App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator
 */
class KnowledgeBaseStructureValidatorEdgeCasesTest extends CIUnitTestCase
{
    private KnowledgeBaseStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new KnowledgeBaseStructureValidator();
    }

    public function testMaxStepsAccepted(): void
    {
        $steps = [];
        for ($i = 1; $i <= 20; $i++) {
            $steps[] = ['step_number' => $i, 'title' => "Step {$i}", 'description' => "Do step {$i}"];
        }
        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors);
    }

    public function testSingleStepIsValid(): void
    {
        $steps  = [['step_number' => 1, 'title' => 'Only Step', 'description' => 'Do it']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors);
    }

    public function testUnsafeKeywordDropTable(): void
    {
        $steps = [['step_number' => 1, 'title' => 'Step', 'description' => 'DROP TABLE users']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testUnsafeKeywordDeleteAll(): void
    {
        $steps = [['step_number' => 1, 'title' => 'Step', 'description' => 'delete all database records']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testMissingDescriptionReturnsError(): void
    {
        $steps = [['step_number' => 1, 'title' => 'Step']];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
    }

    public function testVersionApplicabilityAllCurrentVersions(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'all_current_versions']);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityNotApplicable(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'not_applicable']);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityHistoricalVersion(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'historical_version']);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityVersionRangeRequiresFromTo(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'version_range']);
        $this->assertNotEmpty($errors);
    }

    public function testVersionApplicabilityVersionRangeWithBothFields(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type' => 'version_range',
            'from' => '2.0',
            'to'   => '3.0',
        ]);
        $this->assertEmpty($errors);
    }

    public function testTroubleshootingWithMultipleValidEntries(): void
    {
        $entries = [
            ['symptom' => 'Error 401', 'cause' => 'Auth', 'resolution' => 'Re-login'],
            ['symptom' => 'Slow load', 'cause' => 'DB query', 'resolution' => 'Add index'],
        ];
        $errors = $this->validator->validateTroubleshooting($entries);
        $this->assertEmpty($errors);
    }

    public function testEmptyTroubleshootingIsValid(): void
    {
        $errors = $this->validator->validateTroubleshooting([]);
        $this->assertEmpty($errors);
    }
}
