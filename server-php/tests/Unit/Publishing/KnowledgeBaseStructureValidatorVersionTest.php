<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests for version applicability validation in KnowledgeBaseStructureValidator.
 *
 * @covers \App\Libraries\Publishing\KnowledgeBase\KnowledgeBaseStructureValidator
 */
class KnowledgeBaseStructureValidatorVersionTest extends CIUnitTestCase
{
    private KnowledgeBaseStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new KnowledgeBaseStructureValidator();
    }

    /** @dataProvider validVersionApplicabilityProvider */
    public function testValidVersionApplicabilityTypes(array $applicability): void
    {
        $errors = $this->validator->validateVersionApplicability($applicability);
        $this->assertEmpty($errors, json_encode($errors));
    }

    public static function validVersionApplicabilityProvider(): array
    {
        return [
            'all_current' => [['type' => 'all_current_versions']],
            'not_applicable' => [['type' => 'not_applicable']],
            'historical_version' => [['type' => 'historical_version']],
            'specific_versions' => [['type' => 'specific_versions', 'versions' => ['3.0', '3.1']]],
            'version_range' => [['type' => 'version_range', 'from' => '2.0', 'to' => '3.99']],
            'planned_version' => [['type' => 'planned_version', 'preview_label' => 'Coming in v5.0']],
        ];
    }

    /** @dataProvider invalidVersionApplicabilityProvider */
    public function testInvalidVersionApplicabilityTypes(array $applicability, string $reason): void
    {
        $errors = $this->validator->validateVersionApplicability($applicability);
        $this->assertNotEmpty($errors, "Should fail: {$reason}");
    }

    public static function invalidVersionApplicabilityProvider(): array
    {
        return [
            'empty' => [[], 'empty applicability'],
            'unknown_type' => [['type' => 'unknown'], 'unknown type'],
            'specific_without_versions' => [['type' => 'specific_versions'], 'no versions array'],
            'specific_with_empty_versions' => [['type' => 'specific_versions', 'versions' => []], 'empty versions array'],
            'planned_without_label' => [['type' => 'planned_version'], 'no preview_label'],
            'range_without_from' => [['type' => 'version_range', 'to' => '3.0'], 'missing from'],
            'range_without_to' => [['type' => 'version_range', 'from' => '2.0'], 'missing to'],
        ];
    }

    public function testAllAllowedTypesAreInList(): void
    {
        $allowed = ['all_current_versions', 'specific_versions', 'version_range', 'planned_version', 'historical_version', 'not_applicable'];
        foreach ($allowed as $type) {
            $applicability = ['type' => $type];
            if ($type === 'specific_versions') {
                $applicability['versions'] = ['1.0'];
            }
            if ($type === 'version_range') {
                $applicability['from'] = '1.0';
                $applicability['to']   = '2.0';
            }
            if ($type === 'planned_version') {
                $applicability['preview_label'] = 'Preview';
            }

            $errors = $this->validator->validateVersionApplicability($applicability);
            $this->assertEmpty($errors, "Type '{$type}' should be valid: " . json_encode($errors));
        }
    }
}
