<?php

namespace Tests\Unit\Media;

use App\Libraries\Media\CoverRelevanceScorer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Media\CoverRelevanceScorer
 */
class CoverRelevanceScorerTest extends CIUnitTestCase
{
    private CoverRelevanceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new CoverRelevanceScorer();
    }

    private function asset(int $id, array $tags = [], ?string $stream = null, string $prompt = ''): array
    {
        return [
            'id'               => $id,
            'asset_uuid'       => 'uuid-' . $id,
            'mime'             => 'image/webp',
            'category_tags'    => json_encode($tags),
            'portfolio_stream' => $stream,
            'prompt_used'      => $prompt,
        ];
    }

    public function testArticleKeywordsDropStopwordsAndKeepDomainTerms(): void
    {
        $keywords = $this->scorer->articleKeywords('How to File GSTR-1 Without Common Mistakes', 'Compliance');

        $this->assertContains('gstr', $keywords);
        $this->assertContains('file', $keywords);
        $this->assertNotContains('how', $keywords);
        $this->assertNotContains('without', $keywords);
        $this->assertNotContains('common', $keywords);
    }

    public function testShortDomainAcronymsSurvive(): void
    {
        $keywords = $this->scorer->articleKeywords('TDS Compliance Basics for Growing Companies');

        $this->assertContains('tds', $keywords);
    }

    public function testCategoryTagMatchScoresHighest(): void
    {
        $keywords = $this->scorer->articleKeywords('GST Input Tax Credit Explained', 'compliance');
        $result   = $this->scorer->score($this->asset(1, ['compliance']), $keywords, 'compliance');

        $this->assertGreaterThanOrEqual(6, $result['score']);
        $this->assertContains('category_tag', $result['reasons']);
    }

    public function testUnrelatedAssetScoresZero(): void
    {
        $keywords = $this->scorer->articleKeywords('GST Input Tax Credit Explained', 'compliance');
        $result   = $this->scorer->score($this->asset(2, ['mountains', 'sunset', 'landscape']), $keywords, 'compliance');

        $this->assertSame(0, $result['score']);
    }

    public function testRankDropsAssetsBelowTheFloor(): void
    {
        $keywords = $this->scorer->articleKeywords('GST Input Tax Credit Explained', 'compliance');
        $ranked   = $this->scorer->rank(
            [
                $this->asset(1, ['mountains']),
                $this->asset(2, ['compliance']),
                $this->asset(3, ['sunset', 'highway']),
            ],
            $keywords,
            'compliance',
        );

        $this->assertCount(1, $ranked);
        $this->assertSame(2, $ranked[0]['asset']['id']);
    }

    public function testRankReturnsEmptyWhenNothingIsRelevant(): void
    {
        $keywords = $this->scorer->articleKeywords('TDS Compliance Basics', 'compliance');
        $ranked   = $this->scorer->rank([$this->asset(1, ['mountains']), $this->asset(2)], $keywords, 'compliance');

        $this->assertSame([], $ranked);
    }

    public function testTiesPreserveLeastRecentlyUsedOrder(): void
    {
        $keywords = $this->scorer->articleKeywords('GST Returns Filing', 'compliance');
        $ranked   = $this->scorer->rank(
            [$this->asset(7, ['compliance']), $this->asset(8, ['compliance'])],
            $keywords,
            'compliance',
        );

        $this->assertCount(2, $ranked);
        $this->assertSame(7, $ranked[0]['asset']['id'], 'LRU order must survive equal relevance');
    }

    public function testStreamMatchAloneClearsTheFloor(): void
    {
        $keywords = $this->scorer->articleKeywords('Payroll Month-End Checklist');
        $result   = $this->scorer->score($this->asset(3, [], 'payroll'), $keywords, '', 'payroll');

        $this->assertGreaterThanOrEqual(CoverRelevanceScorer::DEFAULT_MIN_SCORE, $result['score']);
        $this->assertContains('portfolio_stream', $result['reasons']);
    }

    public function testKeywordOverlapOnAssetTagsCounts(): void
    {
        $keywords = $this->scorer->articleKeywords('How to File GSTR-1 Without Common Mistakes');
        $result   = $this->scorer->score($this->asset(4, ['gstr', 'filing']), $keywords);

        $this->assertGreaterThan(0, $result['score']);
    }

    public function testPluralAndSingularTagsMatch(): void
    {
        $keywords = $this->scorer->articleKeywords('Invoice Matching for Small Teams');
        $result   = $this->scorer->score($this->asset(5, ['invoices']), $keywords);

        $this->assertGreaterThan(0, $result['score']);
    }

    public function testPromptKeywordScoreIsCapped(): void
    {
        $keywords = $this->scorer->articleKeywords('GST Invoice Reconciliation Ledger Credit Filing Returns');
        $result   = $this->scorer->score(
            $this->asset(6, [], null, 'gst invoice reconciliation ledger credit filing returns'),
            $keywords,
        );

        $this->assertLessThanOrEqual(3, $result['score']);
    }

    public function testTagsAcceptAlreadyDecodedArrays(): void
    {
        $keywords = $this->scorer->articleKeywords('TDS Compliance Basics', 'compliance');
        $asset    = $this->asset(9);
        $asset['category_tags'] = ['compliance'];

        $this->assertGreaterThanOrEqual(6, $this->scorer->score($asset, $keywords, 'compliance')['score']);
    }
}
