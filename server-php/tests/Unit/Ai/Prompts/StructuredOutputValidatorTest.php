<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Prompts;

use App\Libraries\Ai\Prompts\OutputSchemaRegistry;
use App\Libraries\Ai\Prompts\StructuredOutputValidator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Ai\Prompts\StructuredOutputValidator
 */
class StructuredOutputValidatorTest extends CIUnitTestCase
{
    private StructuredOutputValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new StructuredOutputValidator();
    }

    public function testValidBlogPostPassesValidation(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $data   = [
            'title'          => 'Test Title',
            'summary'        => 'A test summary.',
            'body_html'      => '<p>Body</p>',
            'body_markdown'  => '**Body**',
            'body_plain_text' => 'Body',
            'slug_suggestion' => 'test-title',
            'meta_title'     => 'Test Meta Title',
            'meta_description' => 'Test meta description.',
            'primary_cta'    => 'Learn More',
            'claims_used'    => [],
            'citations_used' => [],
            'risk_notes'     => [],
        ];

        $errors = $this->validator->validate($data, $schema);
        $this->assertEmpty($errors, 'Valid blog post should pass: ' . implode(', ', $errors));
    }

    public function testMissingRequiredFieldFails(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $data   = [
            'summary'     => 'Missing title.',
            'body_html'   => '<p>Body</p>',
            'claims_used' => [],
            'risk_notes'  => [],
        ];

        $errors = $this->validator->validate($data, $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue(
            (bool) array_filter($errors, fn($e) => str_contains($e, 'title'))
        );
    }

    public function testStringTooLongFails(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $data   = [
            'title'          => str_repeat('x', 513),
            'summary'        => 'OK',
            'body_html'      => '<p>B</p>',
            'body_markdown'  => 'B',
            'body_plain_text' => 'B',
            'slug_suggestion' => 'slug',
            'meta_title'     => 'M',
            'meta_description' => 'D',
            'claims_used'    => [],
            'citations_used' => [],
            'risk_notes'     => [],
        ];

        $errors = $this->validator->validate($data, $schema);
        $this->assertTrue(
            (bool) array_filter($errors, fn($e) => str_contains($e, 'title') && str_contains($e, 'most'))
        );
    }

    public function testInvalidPlatformEnumFails(): void
    {
        $schema = OutputSchemaRegistry::get('social_post');
        $data   = [
            'title'          => 'T',
            'summary'        => 'S',
            'body_plain_text' => 'B',
            'platform'       => 'tiktok',
            'hashtags'       => [],
            'claims_used'    => [],
            'citations_used' => [],
            'risk_notes'     => [],
        ];

        $errors = $this->validator->validate($data, $schema);
        $this->assertNotEmpty($errors);
        $this->assertTrue((bool) array_filter($errors, fn($e) => str_contains($e, 'platform')));
    }

    public function testIsValidReturnsTrueOnValidData(): void
    {
        $schema = OutputSchemaRegistry::get('generic');
        $data   = ['title' => 'T', 'summary' => 'S', 'claims_used' => [], 'citations_used' => [], 'risk_notes' => []];
        $this->assertTrue($this->validator->isValid($data, $schema));
    }
}
