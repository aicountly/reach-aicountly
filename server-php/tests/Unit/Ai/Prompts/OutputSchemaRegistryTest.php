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
    public function testAllTypesDefined(): void
    {
        $types = OutputSchemaRegistry::allTypes();
        $this->assertCount(16, $types);
        $this->assertContains('blog_post', $types);
        $this->assertContains('generic', $types);
    }

    public function testHasReturnsTrueForKnownType(): void
    {
        $this->assertTrue(OutputSchemaRegistry::has('blog_post'));
        $this->assertTrue(OutputSchemaRegistry::has('social_post'));
        $this->assertFalse(OutputSchemaRegistry::has('does_not_exist'));
    }

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
            $this->assertArrayHasKey('required', $schema, "Schema '{$type}' must have required");
            $this->assertContains('claims_used', $schema['required'], "Schema '{$type}' must require claims_used");
            $this->assertContains('risk_notes', $schema['required'], "Schema '{$type}' must require risk_notes");
        }
    }
}
