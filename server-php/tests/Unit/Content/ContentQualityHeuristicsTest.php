<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Libraries\Content\ContentQualityHeuristics;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Libraries\Content\ContentQualityHeuristics
 */
final class ContentQualityHeuristicsTest extends TestCase
{
    private function article(string $topic): string
    {
        $p = "<p>{$topic} matters for every registered business. File returns on time, reconcile invoices against the portal, "
            . 'keep books of account up to date and track due dates in a calendar so interest and late fees never pile up. </p>';

        return '<h2>Overview</h2>' . str_repeat($p, 6);
    }

    public function testAGenuineArticlePasses(): void
    {
        $verdict = ContentQualityHeuristics::assess(
            'TDS Compliance Basics for Growing Companies',
            'What TDS deduction and deposit involve.',
            $this->article('TDS deduction, deposit and TDS compliance'),
        );

        $this->assertFalse($verdict['suspicious'], implode(',', $verdict['flags']));
    }

    public function testGenericDigitalContentFillerIsFlagged(): void
    {
        // The live id-5 failure: a TDS title over a body about content creation.
        $body = str_repeat('<p>Digital content creation is an evolving landscape. Brands must craft engaging stories, '
            . 'plan their editorial calendar and measure engagement across channels to grow an audience. </p>', 6);

        $verdict = ContentQualityHeuristics::assess('TDS Compliance Basics for Growing Companies', '', $body);

        $this->assertTrue($verdict['suspicious']);
        $this->assertContains('generic_filler', $verdict['flags']);
        $this->assertContains('title_term_missing:TDS', $verdict['flags']);
        $this->assertContains('title_not_reflected_in_body', $verdict['flags']);
        $this->assertTrue(ContentQualityHeuristics::isStrong($verdict['flags']));
    }

    public function testGenerationMetaTextIsFlagged(): void
    {
        // The live id-1 failure: the request's intent written into the article.
        $body = str_repeat('<p>This content supports the intent draft_generation within the content pipeline. '
            . 'Based on the grounding context provided, the draft generation step produces a GST filing checklist. </p>', 5);

        $verdict = ContentQualityHeuristics::assess('GST filing checklist for small businesses', '', $body);

        $this->assertContains('meta_text:draft_generation', $verdict['flags']);
        $this->assertContains('meta_text:intent', $verdict['flags']);
        $this->assertContains('meta_text:content_pipeline', $verdict['flags']);
        $this->assertContains('meta_text:grounding_context', $verdict['flags']);
        $this->assertTrue(ContentQualityHeuristics::isStrong($verdict['flags']));
    }

    public function testOrdinaryUseOfTheWordIntentIsNotMetaText(): void
    {
        $verdict = ContentQualityHeuristics::assess(
            'GST input tax credit rules',
            '',
            $this->article('The intent of the law is that GST input tax credit follows the invoice'),
        );

        $this->assertNotContains('meta_text:intent', $verdict['flags']);
    }

    /** Openers of the live Aug-2026 posts (Schedule III, GSTR-2B, Input Tax Credit). */
    public function testTheLiveFillerOpenersAreFlagged(): void
    {
        foreach ([
            "In today\u{2019}s fast-paced digital landscape, the demand for content is ever-increasing.",
            'The Evolution of Marketing Content Generation in modern businesses.',
            'Unlocking Marketing Potential: A Guide for growing teams.',
            'The Art of Crafting Effective Marketing Content for every audience.',
        ] as $opener) {
            $verdict = ContentQualityHeuristics::assess('Schedule III financial statements explained', '', '<p>' . $opener . '</p>' . $this->article('Schedule III'));
            $this->assertContains('generic_filler', $verdict['flags'], $opener);
        }
    }

    public function testShortBodyIsFlagged(): void
    {
        $verdict = ContentQualityHeuristics::assess('GST input tax credit rules', '', '<p>GST input tax credit.</p>');

        $this->assertContains('short_body:4', $verdict['flags']);
        $this->assertFalse(ContentQualityHeuristics::isStrong($verdict['flags']));
    }
}
