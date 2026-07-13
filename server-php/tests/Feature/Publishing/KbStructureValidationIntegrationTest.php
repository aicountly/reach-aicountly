<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for KB article structure validation scenarios.
 *
 * @group publishing
 */
class KbStructureValidationIntegrationTest extends CIUnitTestCase
{
    private KnowledgeBaseStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new KnowledgeBaseStructureValidator();
    }

    public function testCompleteHowToArticleStepsPass(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Log in', 'description' => 'Open the AICOUNTLY portal and log in with your credentials.'],
            ['step_number' => 2, 'title' => 'Navigate to Banking', 'description' => 'Click Banking module in the sidebar.'],
            ['step_number' => 3, 'title' => 'Upload Statement', 'description' => 'Click Upload Statement and select your bank file.'],
            ['step_number' => 4, 'title' => 'Run Reconciliation', 'description' => 'Click Reconcile to start the automatic matching.'],
            ['step_number' => 5, 'title' => 'Review Matches', 'description' => 'Verify auto-matched entries and resolve any mismatches.'],
        ];

        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors);
    }

    public function testOutOfOrderStepsAreRejected(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Step 1', 'description' => 'First step'],
            ['step_number' => 3, 'title' => 'Step 3', 'description' => 'Third step (gap)'],
            ['step_number' => 5, 'title' => 'Step 5', 'description' => 'Fifth step (another gap)'],
        ];

        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
        $gapErrors = array_filter($errors, fn($e) => str_contains($e, 'Missing step'));
        $this->assertCount(2, $gapErrors);
    }

    public function testTroubleshootingArticleWithAllFields(): void
    {
        $entries = [
            [
                'symptom'    => 'Cannot upload bank statement',
                'cause'      => 'File format not supported',
                'resolution' => 'Convert file to CSV format and retry',
            ],
            [
                'symptom'    => 'Reconciliation shows no matches',
                'cause'      => 'Account number not mapped',
                'resolution' => 'Map the bank account in Settings > Banking',
            ],
        ];

        $errors = $this->validator->validateTroubleshooting($entries);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityForSpecificVersions(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type'     => 'specific_versions',
            'versions' => ['4.2', '4.3', '4.4'],
        ]);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityForPlannedWithLabel(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type'          => 'planned_version',
            'preview_label' => 'Coming in v5.0',
        ]);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityForVersionRangeWithFromTo(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type' => 'version_range',
            'from' => '3.0',
            'to'   => '4.99',
        ]);
        $this->assertEmpty($errors);
    }

    public function testStepsWithSafeInstructions(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Click Save', 'description' => 'Click the Save button to save your work'],
            ['step_number' => 2, 'title' => 'Download Report', 'description' => 'Download the generated PDF report'],
            ['step_number' => 3, 'title' => 'Share via Email', 'description' => 'Send the report to your accountant'],
        ];

        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors);
    }

    public function testDropTableStepIsUnsafe(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Clean up', 'description' => 'run DROP TABLE users to clear test data'],
        ];
        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
        $unsafeErrors = array_filter($errors, fn($e) => str_contains($e, 'unsafe'));
        $this->assertNotEmpty($unsafeErrors);
    }
}
