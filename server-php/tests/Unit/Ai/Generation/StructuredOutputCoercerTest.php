<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Generation;

use App\Libraries\Ai\Generation\StructuredOutputCoercer;
use App\Libraries\Ai\Prompts\OutputSchemaRegistry;
use App\Libraries\Ai\Prompts\StructuredOutputValidator;
use PHPUnit\Framework\TestCase;

final class StructuredOutputCoercerTest extends TestCase
{
    public function testCoercesPartialBlogPostToValidSchema(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $coercer = new StructuredOutputCoercer();
        $body = str_repeat(
            "Input tax credit (ITC) helps Indian SMEs reduce GST liability when filing returns. ",
            12
        );
        $data = $coercer->coerce([
            'title'          => 'GST basics for SMEs',
            'body_markdown'  => "## Intro\n\n{$body}\n\n## Checklist\n\n{$body}",
        ], $schema);

        $errors = (new StructuredOutputValidator())->validate($data, $schema);
        $this->assertSame([], $errors, implode('; ', $errors));
        $this->assertNotSame('', $data['body_html']);
        $this->assertNotSame('', $data['body_plain_text']);
        $this->assertSame([], $data['claims_used']);
        $this->assertGreaterThanOrEqual(1, $data['reading_time_minutes']);
    }

    public function testCoercesExplicitEmptySummaryAndMetaTitle(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $coercer = new StructuredOutputCoercer();
        $plain = str_repeat('File GSTR-1 on time and reconcile B2B invoices carefully. ', 20);
        $data = $coercer->coerce([
            'title'            => 'GST filing checklist for small businesses',
            'summary'          => '',
            'meta_title'       => '',
            'meta_description' => '',
            'body_html'        => '<p>' . htmlspecialchars($plain) . '</p>',
            'body_markdown'    => $plain,
            'body_plain_text'  => $plain,
            'slug_suggestion'  => '',
            'primary_cta'      => '',
            'claims_used'      => [],
            'citations_used'   => [],
            'risk_notes'       => [],
            'reading_time_minutes' => 0,
            'sections'         => [],
        ], $schema);

        $errors = (new StructuredOutputValidator())->validate($data, $schema);
        $this->assertSame([], $errors, implode('; ', $errors));
        $this->assertNotSame('', $data['summary']);
        $this->assertNotSame('', $data['meta_title']);
        $this->assertNotSame('', $data['meta_description']);
    }

    public function testDoesNotInventUntitledDraftBody(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $coercer = new StructuredOutputCoercer();
        $data = $coercer->coerce([
            'title' => 'Bookkeeping Checklist for Indian SMEs',
        ], $schema);

        $errors = (new StructuredOutputValidator())->validate($data, $schema);
        $this->assertNotSame([], $errors, 'Title-only stubs must fail blog_post schema validation');
        $this->assertTrue(StructuredOutputCoercer::isStubBody(
            $data['body_html'] ?? null,
            $data['body_markdown'] ?? null,
            $data['body_plain_text'] ?? null,
            $data['title'] ?? null,
        ));
    }

    public function testDetectsUntitledDraftStub(): void
    {
        $this->assertTrue(StructuredOutputCoercer::isStubBody(
            '<p>Untitled draft</p>',
            'Untitled draft',
            'Untitled draft',
            'Bookkeeping Checklist for Indian SMEs',
        ));
    }

    public function testSynthesizesBodyFromSectionsWhenBodiesEmpty(): void
    {
        $schema = OutputSchemaRegistry::get('blog_post');
        $coercer = new StructuredOutputCoercer();
        $para = str_repeat(
            'Indian SMEs should reconcile purchase invoices before claiming ITC on GSTR-3B. ',
            10
        );
        $data = $coercer->coerce([
            'title'    => 'GST ITC checklist for SMEs',
            'sections' => [
                ['heading' => 'Why ITC matters', 'body' => $para],
                ['heading' => 'Monthly checklist', 'body' => $para],
            ],
        ], $schema);

        $errors = (new StructuredOutputValidator())->validate($data, $schema);
        $this->assertSame([], $errors, implode('; ', $errors));
        $this->assertStringContainsString('Why ITC matters', $data['body_html']);
        $this->assertGreaterThanOrEqual(200, str_word_count($data['body_plain_text']));
    }
}
