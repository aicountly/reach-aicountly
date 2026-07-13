<?php

namespace Tests\Feature\Publishing;

use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integration tests for KB version applicability in various article scenarios.
 *
 * @group publishing
 */
class KbStructureValidatorVersionIntegrationTest extends CIUnitTestCase
{
    private KnowledgeBaseStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new KnowledgeBaseStructureValidator();
    }

    public function testConceptArticleDoesNotRequireSteps(): void
    {
        // Concept articles don't need steps - this validator handles how-to only
        $errors = $this->validator->validateTroubleshooting([]);
        $this->assertEmpty($errors);
    }

    public function testReleaseGuideWithVersionRange(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type' => 'version_range',
            'from' => '4.0',
            'to'   => '4.99',
        ]);
        $this->assertEmpty($errors);
    }

    public function testIntegrationArticleWithSpecificVersions(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type'     => 'specific_versions',
            'versions' => ['4.2', '4.3'],
        ]);
        $this->assertEmpty($errors);
    }

    public function testFaqArticleWithNotApplicableVersionType(): void
    {
        $errors = $this->validator->validateVersionApplicability(['type' => 'not_applicable']);
        $this->assertEmpty($errors);
    }

    public function testHowToArticleWithCompleteSteps(): void
    {
        $steps = [];
        for ($i = 1; $i <= 8; $i++) {
            $steps[] = [
                'step_number' => $i,
                'title'       => "Step {$i}: Configure",
                'description' => "Description for step {$i} - contains relevant details",
            ];
        }
        $errors = $this->validator->validateSteps($steps);
        $this->assertEmpty($errors, json_encode($errors));
    }

    public function testComplexTroubleshootingScenario(): void
    {
        $entries = [
            [
                'symptom'    => 'GST filing fails with error code GE-501',
                'cause'      => 'GSTIN format mismatch between Reach and GST portal',
                'resolution' => 'Update GSTIN in Settings > Company Profile to match GST portal format',
            ],
            [
                'symptom'    => 'TDS deduction not reflecting in reports',
                'cause'      => 'TDS configuration incomplete for the financial year',
                'resolution' => 'Complete TDS setup in Settings > Tax Configuration > TDS',
            ],
            [
                'symptom'    => 'Bank reconciliation shows 100+ unmatched entries',
                'cause'      => 'Opening balance not entered for the bank account',
                'resolution' => 'Enter opening balance in Banking > Accounts > [Account] > Opening Balance',
            ],
        ];

        $errors = $this->validator->validateTroubleshooting($entries);
        $this->assertEmpty($errors);
    }

    public function testPlannedVersionWithPreviewLabel(): void
    {
        $errors = $this->validator->validateVersionApplicability([
            'type'          => 'planned_version',
            'preview_label' => 'Available in AICOUNTLY v5.0 (Coming Q3 2026)',
        ]);
        $this->assertEmpty($errors);
    }
}
