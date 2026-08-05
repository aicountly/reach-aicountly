<?php

namespace Tests\Unit\Publishing;

use App\Libraries\Publishing\Blog\PublishableContentGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Publishing\Blog\PublishableContentGuard
 */
class PublishableContentGuardTest extends CIUnitTestCase
{
    private PublishableContentGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new PublishableContentGuard();
    }

    private function realArticle(int $words = 400): string
    {
        return '<h2>GST input tax credit</h2><p>'
            . str_repeat('Registered taxpayers reconcile invoices before claiming credit. ', (int) ceil($words / 8))
            . '</p>';
    }

    public function testRealArticleIsPublishable(): void
    {
        $verdict = $this->guard->assessBody($this->realArticle(), 'GST Input Tax Credit Explained');

        $this->assertTrue($verdict['publishable'], implode('; ', $verdict['reasons']));
        $this->assertGreaterThan(300, $verdict['words']);
    }

    public function testUntitledDraftPlaceholderIsRejected(): void
    {
        $html    = '<p>Untitled draft.......................................................</p>';
        $verdict = $this->guard->assessBody($html, 'TDS Compliance Basics for Growing Companies');

        $this->assertFalse($verdict['publishable']);
        $this->assertNotEmpty($verdict['reasons']);
    }

    public function testEmptyBodyIsRejected(): void
    {
        $verdict = $this->guard->assessBody('', 'Anything');

        $this->assertFalse($verdict['publishable']);
        $this->assertSame(['Body is empty'], $verdict['reasons']);
        $this->assertSame(0, $verdict['words']);
    }

    public function testShortButRealBodyIsRejectedOnWordCount(): void
    {
        $html    = '<p>' . str_repeat('This is a genuine sentence about compliance. ', 5) . '</p>';
        $verdict = $this->guard->assessBody($html, 'Short article');

        $this->assertFalse($verdict['publishable']);
        $this->assertStringContainsString('too short', implode(' ', $verdict['reasons']));
    }

    public function testMinWordsIsOverridable(): void
    {
        $html = '<p>' . str_repeat('This is a genuine sentence about compliance. ', 5) . '</p>';

        $this->assertTrue($this->guard->assessBody($html, 'Short article', 20)['publishable']);
    }

    public function testTemplateTokensAreRejected(): void
    {
        $html    = '<p>{{ body }}</p><p>' . str_repeat('filler words here for length. ', 80) . '</p>';
        $verdict = $this->guard->assessBody($html, 'Templated');

        $this->assertFalse($verdict['publishable']);
    }

    public function testArticleMentioningTbdMidSentenceIsStillPublishable(): void
    {
        $html = '<p>' . str_repeat('The filing deadline is confirmed and the rate is TBD until notified. ', 40) . '</p>';

        $this->assertTrue($this->guard->assessBody($html, 'Rates')['publishable']);
    }

    public function testUnusableExcerptDetection(): void
    {
        $this->assertTrue($this->guard->isUnusableExcerpt(''));
        $this->assertTrue($this->guard->isUnusableExcerpt('Untitled draft.................................'));
        $this->assertTrue($this->guard->isUnusableExcerpt('TBD'));
        $this->assertTrue($this->guard->isUnusableExcerpt('Short one'));
        $this->assertFalse($this->guard->isUnusableExcerpt(
            'Input tax credit lets registered businesses offset the GST paid on purchases against output liability.'
        ));
    }

    public function testFirstUsableExcerptSkipsPlaceholders(): void
    {
        $good = 'Filing GSTR-1 on time avoids late fees and keeps your recipients able to claim credit.';

        $this->assertSame($good, $this->guard->firstUsableExcerpt([
            null,
            'Untitled draft........................',
            $good,
        ]));
    }

    public function testFirstUsableExcerptReturnsEmptyWhenAllPlaceholders(): void
    {
        $this->assertSame('', $this->guard->firstUsableExcerpt(['', 'TBD', 'Untitled draft....................']));
    }

    public function testToPlainTextKeepsWordBoundariesAcrossBlockTags(): void
    {
        $text = PublishableContentGuard::toPlainText('<h2>Introduction</h2><p>Body text</p>');

        $this->assertSame('Introduction Body text', $text);
    }

    public function testCountWordsHandlesNonAsciiScript(): void
    {
        $this->assertSame(3, PublishableContentGuard::countWords('जीएसटी इनपुट क्रेडिट'));
    }
}
