<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Workflow integration tests for KB validation covering all article types.
 *
 * @group publishing
 */
class KbValidationWorkflowTest extends CIUnitTestCase
{
    private KnowledgeBaseStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new KnowledgeBaseStructureValidator();
    }

    public function testGstHowToArticleSteps(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Enable GST', 'description' => 'Go to Settings > Tax and enable GST'],
            ['step_number' => 2, 'title' => 'Enter GSTIN', 'description' => 'Enter your 15-digit GSTIN number'],
            ['step_number' => 3, 'title' => 'Map HSN Codes', 'description' => 'Map products to their HSN/SAC codes'],
            ['step_number' => 4, 'title' => 'Generate Returns', 'description' => 'Click Tax > GSTR to generate returns'],
        ];

        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors);
    }

    public function testBankReconciliationHowToSteps(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Open Banking', 'description' => 'Navigate to the Banking module'],
            ['step_number' => 2, 'title' => 'Select Account', 'description' => 'Choose the bank account to reconcile'],
            ['step_number' => 3, 'title' => 'Import Statement', 'description' => 'Import the bank statement CSV'],
            ['step_number' => 4, 'title' => 'Auto Match', 'description' => 'Run automatic transaction matching'],
            ['step_number' => 5, 'title' => 'Review Results', 'description' => 'Review and confirm matched transactions'],
            ['step_number' => 6, 'title' => 'Resolve Discrepancies', 'description' => 'Manually resolve unmatched entries'],
        ];

        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors);
    }

    public function testTroubleshootingCommonGstErrors(): void
    {
        $entries = [
            [
                'symptom'    => 'GSTIN validation fails',
                'cause'      => 'Incorrect GSTIN format entered',
                'resolution' => 'Verify your GSTIN has exactly 15 characters in correct format',
            ],
            [
                'symptom'    => 'HSN code not found',
                'cause'      => 'Product category not mapped to HSN',
                'resolution' => 'Go to Products > Categories and map each category to HSN code',
            ],
        ];

        $errors = $this->validator->validateTroubleshooting($entries);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityForCurrentProduct(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'all_current_versions']);
        $this->assertEmpty($errors);
    }

    public function testVersionApplicabilityForSpecificFeatureVersion(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type'     => 'specific_versions',
            'versions' => ['4.3', '4.4', '4.5'],
        ]);
        $this->assertEmpty($errors);
    }

    public function testStepsMustBeContiguous(): void
    {
        $steps = [
            ['step_number' => 1, 'title' => 'Step 1', 'description' => 'First'],
            ['step_number' => 2, 'title' => 'Step 2', 'description' => 'Second'],
            ['step_number' => 4, 'title' => 'Step 4', 'description' => 'Fourth (gap at 3)'],
        ];

        $errors = $this->validator->validateSteps($steps);
        $this->assertNotEmpty($errors);
        $gapErrors = array_filter($errors, fn($e) => str_contains($e, 'Missing step number: 3'));
        $this->assertNotEmpty($gapErrors);
    }
}
