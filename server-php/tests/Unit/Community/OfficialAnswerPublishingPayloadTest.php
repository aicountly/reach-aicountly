<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\OfficialAnswerPublishingService;
use PHPUnit\Framework\TestCase;

/**
 * The create_question payload is what makes an official answer renderable on
 * aicountly.com: without a question record on the public site there is no page
 * for the answer to live on. These tests cover the pure payload construction.
 */
final class OfficialAnswerPublishingPayloadTest extends TestCase
{
    private const QUESTION = [
        'id'       => 7,
        'uuid'     => '4f2c1a9e-1111-4222-8333-abcdef012345',
        'title'    => 'Is an e-way bill needed for intra-state movement below 50,000 rupees?',
        'body'     => 'Asking about intra-state consignments.',
        'category' => 'GST Compliance',
        'language' => 'en',
        'tags'     => '{"gst","eway-bill"}',
    ];

    public function testPayloadCarriesTheContractFields(): void
    {
        $payload = OfficialAnswerPublishingService::buildQuestionPayload(self::QUESTION);

        $this->assertSame(self::QUESTION['title'], $payload['title']);
        $this->assertSame(self::QUESTION['body'], $payload['body']);
        $this->assertSame('gst-compliance', $payload['category_slug']);
        $this->assertSame('official_question', $payload['source_type']);
        $this->assertSame('index,follow', $payload['robots_directive']);
        $this->assertSame('en', $payload['language']);
    }

    public function testCategorySlugFallsBackToGeneral(): void
    {
        $payload = OfficialAnswerPublishingService::buildQuestionPayload(['title' => 'A question']);

        $this->assertSame('general', $payload['category_slug']);
    }

    public function testSlugIsUrlSafeAndDeterministic(): void
    {
        $slug = OfficialAnswerPublishingService::questionSlug(self::QUESTION);

        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
        $this->assertStringStartsWith('is-an-e-way-bill-needed-for-intra-state-movement-below-50-000-rupees', $slug);
        $this->assertSame($slug, OfficialAnswerPublishingService::questionSlug(self::QUESTION));
    }

    public function testSlugIsUniquePerQuestion(): void
    {
        $other = array_merge(self::QUESTION, ['uuid' => 'aaaaaaaa-1111-4222-8333-abcdef012345']);

        $this->assertNotSame(
            OfficialAnswerPublishingService::questionSlug(self::QUESTION),
            OfficialAnswerPublishingService::questionSlug($other)
        );
    }

    public function testSlugSurvivesAnEmptyTitle(): void
    {
        $this->assertSame('question', OfficialAnswerPublishingService::questionSlug([]));
    }

    public function testTagsAreDecodedFromThePostgresArrayLiteral(): void
    {
        $this->assertSame(
            ['gst', 'eway-bill'],
            OfficialAnswerPublishingService::buildQuestionPayload(self::QUESTION)['tags']
        );
    }

    public function testEmptyTagLiteralsDecodeToAnEmptyList(): void
    {
        $this->assertSame([], OfficialAnswerPublishingService::parsePgArray('{}'));
        $this->assertSame([], OfficialAnswerPublishingService::parsePgArray(null));
        $this->assertSame([], OfficialAnswerPublishingService::parsePgArray(''));
    }

    public function testAlreadyDecodedArraysArePassedThrough(): void
    {
        $this->assertSame(['a', 'b'], OfficialAnswerPublishingService::parsePgArray(['a', 'b']));
    }
}
