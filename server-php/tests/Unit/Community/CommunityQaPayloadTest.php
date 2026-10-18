<?php

declare(strict_types=1);

namespace Tests\Unit\Community;

use App\Enums\CommunityAnswerStatus;
use App\Libraries\Community\Automation\CommunityQaAutomationService;
use App\Libraries\Community\Automation\CommunityQaPayload;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * What the community-qa routine may submit, and the messages it gets back
 * when it may not (returned verbatim as HTTP 422).
 *
 * @internal
 */
final class CommunityQaPayloadTest extends CIUnitTestCase
{
    private static function faq(array $overrides = []): array
    {
        return $overrides + [
            'external_key'   => 'faq-gst-1',
            'question_title' => 'When is GSTR-1 due for monthly filers?',
            'question_body'  => '',
            'category_slug'  => 'gst',
            'answer_html'    => '<p>GSTR-1 for monthly filers is due on the 11th of the following month.</p>',
            'provenance'     => ['generated_by_model' => 'claude-sonnet', 'routine_run_id' => 'session-1'],
        ];
    }

    public function testAValidFaqIsNormalised(): void
    {
        $in = CommunityQaPayload::faq(self::faq([
            'product_slug'      => 'smart-books',
            'answer_html'       => '<p>See <a href="https://www.gst.gov.in/">the portal</a>.<br/>File by the 11th of the next month.</p>',
            'source_references' => [['title' => 'GST portal', 'url' => 'https://www.gst.gov.in/'], 'https://cbic-gst.gov.in/'],
        ]));

        $this->assertSame('smart_books', $in['product_slug'], 'hyphenated slugs map to the product catalogue');
        $this->assertStringContainsString('rel="nofollow"', $in['answer_html'], 'stored purified');
        $this->assertSame('See the portal. File by the 11th of the next month.', $in['short_answer'], 'derived from the answer when not given');
        $this->assertSame('cbic-gst.gov.in', $in['sources'][1]['source_title']);
        $this->assertSame('cited', $in['sources'][0]['coverage_status']);
    }

    public function testReachCategoriesMapToTheirPublicSlug(): void
    {
        $this->assertSame('audit-accounting', CommunityQaPayload::faq(self::faq(['category_slug' => 'accounting']))['category_slug']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'no key'            => [['external_key' => ''], 'external_key is required'],
            'bad key'           => [['external_key' => 'has spaces in it'], 'external_key must be'],
            'short title'       => [['question_title' => 'GST?'], 'question_title is required'],
            'unknown category'  => [['category_slug' => 'crypto'], 'category_slug must be one of'],
            'unknown product'   => [['product_slug' => 'spaceship'], 'product_slug is not an AICOUNTLY product'],
            'no answer'         => [['answer_html' => ''], 'answer_html is required'],
            'script in answer'  => [['answer_html' => '<p>Fine.</p><script>alert(1)</script><p>More text here please.</p>'], 'markup the site removes'],
            'one word answer'   => [['answer_html' => '<p>Yes.</p>'], 'answer_html is too short'],
            'no model'          => [['provenance' => []], 'provenance.generated_by_model is required'],
            'bad source url'    => [['source_references' => [['url' => 'javascript:alert(1)']]], 'source_references[0].url'],
        ];
    }

    /** @dataProvider invalid */
    public function testInvalidSubmissionsSayWhatToFix(array $overrides, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        CommunityQaPayload::faq(self::faq($overrides));
    }

    public function testAMemberAnswerNeedsTheQuestionUuidAndDefaultsItsKey(): void
    {
        $in = CommunityQaPayload::memberAnswer([
            'question_id' => 'C0A8B4DE-7F3E-4B8A-9C1D-2E5F6A7B8C9D',
            'answer_html' => '<p>Record it as an expense paid from petty cash, with the voucher attached.</p>',
            'provenance'  => ['generated_by_model' => 'claude-sonnet'],
        ]);
        $this->assertSame('member-answer:c0a8b4de-7f3e-4b8a-9c1d-2e5f6a7b8c9d', $in['external_key']);
        $this->assertTrue($in['key_defaulted'], 'a defaulted key may move on to :2 after an answer that was not published');
        $this->assertFalse(CommunityQaPayload::memberAnswer([
            'question_id'  => 'C0A8B4DE-7F3E-4B8A-9C1D-2E5F6A7B8C9D',
            'external_key' => 'my-key',
            'answer_html'  => '<p>Record it as an expense paid from petty cash, with the voucher attached.</p>',
            'provenance'   => ['generated_by_model' => 'claude-sonnet'],
        ])['key_defaulted']);

        $this->expectException(InvalidArgumentException::class);
        CommunityQaPayload::memberAnswer(['question_id' => '42', 'answer_html' => '<p>x</p>']);
    }

    public function testTitlesAreComparedIgnoringCaseAndPunctuation(): void
    {
        $this->assertSame(
            CommunityQaAutomationService::normaliseTitle('When is GSTR-1 due?'),
            CommunityQaAutomationService::normaliseTitle('  when is gstr 1 due ')
        );
    }

    public function testStatusPathsFollowOnlyLegalTransitions(): void
    {
        $path = CommunityQaAutomationService::path(
            CommunityAnswerStatus::DraftRequested,
            CommunityAnswerStatus::DraftGenerated,
            [CommunityAnswerStatus::class, 'validTransitions']
        );
        $this->assertSame([
            [CommunityAnswerStatus::DraftRequested, CommunityAnswerStatus::Generating],
            [CommunityAnswerStatus::Generating, CommunityAnswerStatus::DraftGenerated],
        ], $path);

        foreach (CommunityQaAutomationService::path(CommunityAnswerStatus::EditorialReview, CommunityAnswerStatus::Archived, [CommunityAnswerStatus::class, 'validTransitions']) as [$from, $to]) {
            $this->assertTrue($from->canTransitionTo($to));
        }

        $this->expectException(\RuntimeException::class);
        CommunityQaAutomationService::path(CommunityAnswerStatus::Archived, CommunityAnswerStatus::Published, [CommunityAnswerStatus::class, 'validTransitions']);
    }

    public function testVettingRetryBackoffDoublesFromFiveMinutesToAnHour(): void
    {
        $this->assertSame(300, CommunityQaAutomationService::backoffSeconds(1));
        $this->assertSame(600, CommunityQaAutomationService::backoffSeconds(2));
        $this->assertSame(2400, CommunityQaAutomationService::backoffSeconds(4));
        $this->assertSame(3600, CommunityQaAutomationService::backoffSeconds(9));
    }

    public function testMixTargetsCoverTheLaunchAppsAndAddUpToOne(): void
    {
        $products = array_filter(array_column(CommunityQaAutomationService::MIX_TARGETS, 'product_slug'));
        foreach (['smart_books', 'billing', 'inventory', 'notes'] as $launchApp) {
            $this->assertContains($launchApp, $products);
        }
        $this->assertEqualsWithDelta(1.0, array_sum(array_column(CommunityQaAutomationService::MIX_TARGETS, 'share')), 0.0001);
    }
}
