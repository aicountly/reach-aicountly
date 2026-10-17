<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\Visibility\MentionParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Intelligence\Visibility\MentionParser
 */
final class MentionParserTest extends CIUnitTestCase
{
    private const BRAND = ['AICOUNTLY', 'aicountly.com', 'AI Countly'];

    /** @return list<array{id:int, name:string, aliases:list<string>}> */
    private function competitors(): array
    {
        return [
            ['id' => 1, 'name' => 'Tally', 'aliases' => ['TallyPrime', 'Tally Prime', 'tallysolutions.com']],
            ['id' => 2, 'name' => 'Zoho Books', 'aliases' => ['Zoho', 'Zoho Invoice']],
            ['id' => 3, 'name' => 'Vyapar', 'aliases' => ['Vyapar App']],
            ['id' => 4, 'name' => 'Busy', 'aliases' => ['BUSY Accounting']],
            ['id' => 5, 'name' => 'QuickBooks', 'aliases' => []],
        ];
    }

    private function parse(string $text, string $question = '', array $citations = []): array
    {
        return (new MentionParser())->parse($text, self::BRAND, $this->competitors(), $citations, $question);
    }

    public function testBrandMentionIsCaseInsensitiveAndMatchesAliases(): void
    {
        $this->assertTrue($this->parse('You could also try Aicountly for GST billing.')['brand']['mentioned']);
        $this->assertTrue($this->parse('see https://www.aicountly.com/billing for details')['brand']['mentioned']);
        $this->assertTrue($this->parse('AI   Countly is a newer option.')['brand']['mentioned']);

        $none = $this->parse('Zoho Books and Tally are the usual picks.');
        $this->assertFalse($none['brand']['mentioned']);
        $this->assertNull($none['brand']['rank']);
        $this->assertNull($none['brand']['excerpt']);
    }

    public function testWholeWordsOnly(): void
    {
        $this->assertFalse($this->parse('MyAICOUNTLYClone is not us.')['brand']['mentioned']);
        $r = $this->parse('QuickBooksOnline is one token here.');
        $this->assertSame([], $r['competitors']);
    }

    public function testCommonWordAliasesMustBeCapitalised(): void
    {
        $r = $this->parse('Keep a tally of invoices; a busy shop needs speed.');
        $this->assertSame([], $r['competitors']);

        $r = $this->parse('Many shops still run TALLY or Busy for accounting.');
        $names = array_column($r['competitors'], 'name');
        $this->assertSame(['Tally', 'Busy'], $names);
    }

    public function testCompetitorAliasMapsToCanonicalName(): void
    {
        $r = $this->parse('TallyPrime is popular. Zoho Invoice is free for small teams.');
        $this->assertSame(['Tally', 'Zoho Books'], array_column($r['competitors'], 'name'));
        $this->assertSame('TallyPrime', $r['competitors'][0]['matched']);
        $this->assertSame(1, $r['competitors'][0]['id']);
    }

    public function testRankFromNumberedListUsesTheItemSubjectOnly(): void
    {
        $text = "Here are the top options:\n\n"
            . "1. **Zoho Books** – cloud accounting, a good Tally alternative.\n"
            . "2. **Vyapar**: billing and inventory for small shops.\n"
            . "3. **AICOUNTLY Smart Books** — AI-first GST accounting.\n"
            . "4. TallyPrime (desktop)\n\n"
            . "QuickBooks is also used but less common in India.";
        $r = $this->parse($text);

        $this->assertTrue($r['list_detected']);
        $this->assertSame(3, $r['brand']['rank']);
        $byName = array_column($r['competitors'], 'rank', 'name');
        $this->assertSame(1, $byName['Zoho Books']);
        $this->assertSame(2, $byName['Vyapar']);
        // Mentioned in Zoho's description first, but its own item is #4.
        $this->assertSame(4, $byName['Tally']);
        // Mentioned only in prose after the list: no rank.
        $this->assertNull($byName['QuickBooks']);
        $this->assertSame(['Zoho Books', 'Vyapar', 'AICOUNTLY', 'Tally'], array_column($r['ranked'], 'name'));
    }

    public function testRankFromBulletsWithBoldLabels(): void
    {
        $text = "- **Best overall:** Zoho Books — strong GST support\n"
            . "- **Best for traders:** Vyapar\n"
            . "- **Best AI option:** AICOUNTLY\n";
        $r = $this->parse($text);
        $this->assertSame(3, $r['brand']['rank']);
        $this->assertSame(1, array_column($r['competitors'], 'rank', 'name')['Zoho Books']);
    }

    public function testRankFromHeadingsIgnoresSectionTitles(): void
    {
        $text = "## Overview\nSome intro.\n\n### Zoho Books\nGood.\n\n### AICOUNTLY\nNewer.\n\n## Conclusion\nPick one.";
        $r = $this->parse($text);
        $this->assertSame(2, $r['brand']['rank']);
    }

    public function testRankFromMarkdownTable(): void
    {
        $text = "| Software | Best for |\n|---|---|\n| Tally | Desktop |\n| AICOUNTLY | AI GST |\n";
        $r = $this->parse($text);
        $this->assertSame(2, $r['brand']['rank']);
        $this->assertSame(1, $r['competitors'][0]['rank']);
    }

    public function testNumberedListRestartStartsANewList(): void
    {
        $text = "Accounting:\n1. Zoho Books\n2. Tally\n\nBilling:\n1. AICOUNTLY\n2. Vyapar\n";
        $r = $this->parse($text);
        $this->assertSame(1, $r['brand']['rank']);
        $this->assertSame(['Zoho Books', 'Tally', 'AICOUNTLY', 'Vyapar'], array_column($r['ranked'], 'name'));
    }

    public function testEntitiesNamedInTheQuestionAreFlagged(): void
    {
        $r = $this->parse('Alternatives include Zoho Books and AICOUNTLY. Tally remains popular.', 'What are the best Tally alternatives?');
        $flags = array_column($r['competitors'], 'in_question', 'name');
        $this->assertTrue($flags['Tally']);
        $this->assertFalse($flags['Zoho Books']);
        $this->assertFalse($r['brand']['in_question']);
    }

    public function testCitationsFromProviderAndTextAndBrandCited(): void
    {
        $r = $this->parse(
            'See https://vyaparapp.in/pricing. and (https://aicountly.com/billing)',
            '',
            [['url' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/abc', 'title' => 'zoho.com'], ['url' => 'https://vyaparapp.in/pricing']]
        );
        $this->assertSame(['zoho.com', 'vyaparapp.in', 'aicountly.com'], array_column($r['citations'], 'domain'));
        $this->assertSame(['provider', 'provider', 'text'], array_column($r['citations'], 'source'));
        $this->assertTrue($r['brand_cited']);
        $this->assertSame([1, 2, 3], array_column($r['citations'], 'order'));
    }

    public function testExcerptSurroundsFirstMention(): void
    {
        $r = $this->parse(str_repeat('Filler text. ', 30) . 'AICOUNTLY offers AI bookkeeping. ' . str_repeat('More. ', 60));
        $this->assertStringContainsString('AICOUNTLY offers AI bookkeeping', (string) $r['brand']['excerpt']);
        $this->assertStringStartsWith('…', (string) $r['brand']['excerpt']);
        $this->assertLessThanOrEqual(400, mb_strlen((string) $r['brand']['excerpt']));
    }
}
