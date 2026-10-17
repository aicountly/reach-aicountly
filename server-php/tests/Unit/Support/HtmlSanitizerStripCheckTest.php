<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Libraries\HtmlSanitizer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The official-answer validation and moderation gates asked whether the
 * sanitiser changed an answer by comparing purify($html) with $html byte for
 * byte. HTMLPurifier re-spells almost every real answer (rel on links, <br />,
 * entities, quotes, closing tags), so nearly all of them were reported as
 * "unsafe HTML" and stalled. wouldStripContent() compares what survives.
 *
 * @internal
 */
final class HtmlSanitizerStripCheckTest extends CIUnitTestCase
{
    /** @return array<string, array{string}> */
    public static function normalisedOnly(): array
    {
        return [
            'link without rel'      => ['<p>See <a href="https://www.gst.gov.in/">the GST portal</a> &amp; file.</p>'],
            'br spellings'          => ['<p>Line one<br/>Line two<br>three</p>'],
            'named entities'        => ['<p>Rs.&nbsp;5,000 &mdash; due “soon”</p>'],
            'unclosed paragraphs'   => ['<p>First<p>Second'],
            'single quotes'         => ["<p><a href='https://x.com' title='T'>x</a></p>"],
            'upper-case tags'       => ['<P><STRONG>Bold</STRONG></P>'],
            'empty paragraph'       => ['<p></p><p>Text</p>'],
            'harmless span'         => ['<p><span>plain</span> text</p>'],
            'harmless div wrapper'  => ['<div><p>a</p></div>'],
            'target and rel'        => ['<p><a href="https://x.com" target="_blank" rel="noopener">x</a></p>'],
            'list with newlines'    => ["<ul>\n<li>One</li>\n<li>Two</li>\n</ul>"],
            'plain text'            => ['Just a plain sentence without tags.'],
            'already purified'      => ['<p>See <a href="https://x.com" rel="nofollow noopener">x</a></p>'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function stripsSomething(): array
    {
        return [
            'script'              => ['<p>Hi</p><script>alert(1)</script>'],
            'event handler'       => ['<p onclick="x()">Hi</p>'],
            'javascript link'     => ['<p><a href="javascript:alert(1)">x</a></p>'],
            'style attribute'     => ['<p style="color:red">x</p>'],
            'image with handler'  => ['<p>x<img src="https://evil.example/x.png" onerror="y()"></p>'],
            'iframe'              => ['<iframe src="https://evil.example"></iframe><p>x</p>'],
            'handler on span'     => ['<p><span onmouseover="z()">x</span></p>'],
        ];
    }

    /** @dataProvider normalisedOnly */
    public function testNormalisationIsNotReportedAsStripping(string $html): void
    {
        $sanitizer = new HtmlSanitizer();

        $this->assertFalse($sanitizer->wouldStripContent($html), 'purified: ' . $sanitizer->purify($html));
    }

    /** @dataProvider stripsSomething */
    public function testRemovedContentIsStillReported(string $html): void
    {
        $this->assertTrue((new HtmlSanitizer())->wouldStripContent($html));
    }

    public function testTheOldByteComparisonFlaggedOrdinaryAnswers(): void
    {
        $answer    = '<p>File GSTR-1 by the 11th. See <a href="https://www.gst.gov.in/">the portal</a>.</p>';
        $sanitizer = new HtmlSanitizer();

        $this->assertNotSame($answer, $sanitizer->purify($answer), 'the purifier re-spells this answer');
        $this->assertFalse($sanitizer->wouldStripContent($answer));
    }
}
