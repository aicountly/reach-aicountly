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
        $data = $coercer->coerce([
            'title'          => 'GST basics',
            'body_markdown'  => '## Intro\n\nInput tax credit matters for SMEs.',
        ], $schema);

        $errors = (new StructuredOutputValidator())->validate($data, $schema);
        $this->assertSame([], $errors, implode('; ', $errors));
        $this->assertNotSame('', $data['body_html']);
        $this->assertNotSame('', $data['body_plain_text']);
        $this->assertSame([], $data['claims_used']);
        $this->assertGreaterThanOrEqual(1, $data['reading_time_minutes']);
    }
}
