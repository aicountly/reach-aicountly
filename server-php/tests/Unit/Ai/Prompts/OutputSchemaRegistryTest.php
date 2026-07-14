<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Prompts;

use App\Libraries\Ai\Prompts\OutputSchemaRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Prompts\OutputSchemaRegistry
 */
class OutputSchemaRegistryTest extends CIUnitTestCase
{
    /**
     * Canonical list of all 26 expected schema type identifiers.
     *
     * Phase 3 original 16 + Phase 5 added 10 community_answer.* types.
     * Update this list deliberately whenever a new schema is intentionally added.
     */
    private const EXPECTED_TYPES = [
        // Phase 3 — 16 governed schemas
        'blog_post',
        'landing_page',
        'social_post',
        'email_campaign',
        'case_study',
        'whitepaper',
        'product_description',
        'faq',
        'press_release',
        'newsletter',
        'ad_copy',
        'video_script',
        'seo_meta',
        'knowledge_base',
        'testimonial',
        'generic',
        // Phase 5 — 10 community official answer schemas
        'community_answer.concise',
        'community_answer.detailed',
        'community_answer.troubleshooting',
        'community_answer.product_feature',
        'community_answer.compliance',
        'community_answer.clarification',
        'community_answer.duplicate_response',
        'community_answer.correction',
        'community_answer.summary',
        'community_answer.translation',
    ];

    // -------------------------------------------------------------------------
    // Schema inventory
    // -------------------------------------------------------------------------

    public function testAllTypesDefined(): void
    {
        $types = OutputSchemaRegistry::allTypes();

        // Exact count derived from the explicit expected list
        $this->assertCount(count(self::EXPECTED_TYPES), $types);

        // Exact membership — every expected type must be present
        foreach (self::EXPECTED_TYPES as $expected) {
            $this->assertContains($expected, $types, "Expected schema type '{$expected}' is missing from allTypes()");
        }

        // No extra types beyond what is expected
        foreach ($types as $actual) {
            $this->assertContains($actual, self::EXPECTED_TYPES, "Unexpected schema type '{$actual}' found in allTypes()");
        }
    }

    public function testNoTypesAreDuplicated(): void
    {
        $types  = OutputSchemaRegistry::allTypes();
        $unique = array_unique($types);
        $this->assertSame(
            count($unique),
            count($types),
            'allTypes() must not contain duplicate schema identifiers'
        );
    }

    // -------------------------------------------------------------------------
    // Phase 0–4 schemas remain present
    // -------------------------------------------------------------------------

    public function testAllPhase3SchemasPresent(): void
    {
        $phase3 = array_slice(self::EXPECTED_TYPES, 0, 16);
        $types  = OutputSchemaRegistry::allTypes();
        foreach ($phase3 as $type) {
            $this->assertContains($type, $types, "Phase 3 schema '{$type}' must remain in registry");
        }
    }

    // -------------------------------------------------------------------------
    // Phase 5 community schemas present
    // -------------------------------------------------------------------------

    public function testAllPhase5CommunitySchemasPresent(): void
    {
        $phase5 = array_slice(self::EXPECTED_TYPES, 16);
        $types  = OutputSchemaRegistry::allTypes();
        foreach ($phase5 as $type) {
            $this->assertContains($type, $types, "Phase 5 community schema '{$type}' must be in registry");
        }
        $this->assertCount(10, $phase5, '10 Phase 5 community schemas expected');
    }

    // -------------------------------------------------------------------------
    // has() helper
    // -------------------------------------------------------------------------

    public function testHasReturnsTrueForKnownType(): void
    {
        $this->assertTrue(OutputSchemaRegistry::has('blog_post'));
        $this->assertTrue(OutputSchemaRegistry::has('social_post'));
        $this->assertTrue(OutputSchemaRegistry::has('community_answer.concise'));
        $this->assertFalse(OutputSchemaRegistry::has('does_not_exist'));
    }

    // -------------------------------------------------------------------------
    // Schema structure — Phase 3 types
    // -------------------------------------------------------------------------

    public function testGetReturnsBlogPostSchema(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $this->assertSame('object', $schema['type']);
        $this->assertContains('title', $schema['required']);
        $this->assertContains('body_html', $schema['required']);
        $this->assertContains('meta_title', $schema['required']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }

    public function testGetReturnsSocialPostSchema(): void
    {
        $schema = OutputSchemaRegistry::get('social_post');
        $this->assertContains('platform', $schema['required']);
        $this->assertContains('hashtags', $schema['required']);
    }

    public function testGetFallsBackToGenericForUnknownType(): void
    {
        $schema = OutputSchemaRegistry::get('unknown_type');
        $this->assertSame('object', $schema['type']);
        $this->assertContains('title', $schema['required']);
    }

    // -------------------------------------------------------------------------
    // Global registry contract — ALL 26 schemas
    // -------------------------------------------------------------------------

    public function testAllSchemasHaveTypeObject(): void
    {
        foreach (OutputSchemaRegistry::allTypes() as $type) {
            $schema = OutputSchemaRegistry::get($type);
            $this->assertSame('object', $schema['type'], "Schema for '{$type}' must be type object");
        }
    }

    public function testAllSchemasHaveRequiredFields(): void
    {
        foreach (OutputSchemaRegistry::allTypes() as $type) {
            $schema = OutputSchemaRegistry::get($type);
            $this->assertArrayHasKey('required', $schema, "Schema '{$type}' must have a required array");
            $this->assertContains('claims_used',  $schema['required'], "Schema '{$type}' must require claims_used");
            $this->assertContains('risk_notes',   $schema['required'], "Schema '{$type}' must require risk_notes");
            $this->assertContains('citations_used', $schema['required'], "Schema '{$type}' must require citations_used");
        }
    }

    public function testAllSchemasHavePropertiesForRequiredFields(): void
    {
        foreach (OutputSchemaRegistry::allTypes() as $type) {
            $schema = OutputSchemaRegistry::get($type);
            foreach ($schema['required'] as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $schema['properties'],
                    "Schema '{$type}': required field '{$field}' must be declared in properties"
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Phase 5 community schemas — specific contract
    // -------------------------------------------------------------------------

    public function testCommunityAnswerSchemasRequireClaimsUsed(): void
    {
        $community = array_filter(self::EXPECTED_TYPES, fn($t) => str_starts_with($t, 'community_answer.'));
        foreach ($community as $type) {
            $schema = OutputSchemaRegistry::get($type);
            $this->assertContains(
                'claims_used',
                $schema['required'],
                "Community schema '{$type}' must require claims_used"
            );
        }
    }

    public function testCommunityAnswerSchemasRequireRiskNotes(): void
    {
        $community = array_filter(self::EXPECTED_TYPES, fn($t) => str_starts_with($t, 'community_answer.'));
        foreach ($community as $type) {
            $schema = OutputSchemaRegistry::get($type);
            $this->assertContains(
                'risk_notes',
                $schema['required'],
                "Community schema '{$type}' must require risk_notes"
            );
        }
    }

    public function testCommunityAnswerSchemasHaveAnswerSpecificFields(): void
    {
        $community = array_filter(self::EXPECTED_TYPES, fn($t) => str_starts_with($t, 'community_answer.'));
        foreach ($community as $type) {
            $schema = OutputSchemaRegistry::get($type);
            $this->assertContains('answer_title',  $schema['required'], "'{$type}' must require answer_title");
            $this->assertContains('answer_body',   $schema['required'], "'{$type}' must require answer_body");
            $this->assertContains('short_answer',  $schema['required'], "'{$type}' must require short_answer");
        }
    }

    public function testCommunityAnswerClaimsUsedIsArrayOfObjects(): void
    {
        $schema = OutputSchemaRegistry::get('community_answer.concise');
        $claimsDef = $schema['properties']['claims_used'];
        $this->assertSame('array', $claimsDef['type']);
        $this->assertSame('object', $claimsDef['items']['type']);
    }

    public function testValidPhase5FixtureIncludesClaimsUsed(): void
    {
        // Verify a valid structured output fixture would satisfy the schema contract
        $fixture = [
            'claims_used'    => [['claim_id' => 1, 'claim_text' => 'GST is 18%', 'confidence' => 'high']],
            'citations_used' => ['source_id:42'],
            'risk_notes'     => ['Consult a CA for jurisdiction-specific advice.'],
            'answer_title'   => 'How GST is calculated',
            'answer_body'    => 'GST is calculated on the taxable value of goods or services...',
            'short_answer'   => 'GST applies at rates ranging from 0% to 28%.',
            'source_references'       => [],
            'risk_classification'     => 'medium',
            'limitations'             => [],
            'recommended_disclosure'  => 'This is general guidance.',
            'requires_professional_review' => false,
            'requires_legal_review'        => false,
            'requires_product_review'      => false,
        ];

        $schema = OutputSchemaRegistry::get('community_answer.concise');
        foreach ($schema['required'] as $field) {
            $this->assertArrayHasKey($field, $fixture, "Fixture is missing required field '{$field}'");
        }
        $this->assertIsArray($fixture['claims_used']);
        $this->assertNotEmpty($fixture['claims_used']);
    }

    public function testCommunityAnswerSchemasHaveAdditionalPropertiesFalse(): void
    {
        $community = array_filter(self::EXPECTED_TYPES, fn($t) => str_starts_with($t, 'community_answer.'));
        foreach ($community as $type) {
            $schema = OutputSchemaRegistry::get($type);
            $this->assertFalse(
                $schema['additionalProperties'],
                "Community schema '{$type}' must have additionalProperties: false"
            );
        }
    }
}
