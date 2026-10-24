<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Libraries\Content\BrandCasing;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Libraries\Content\BrandCasing
 *
 * @internal
 */
final class BrandCasingTest extends TestCase
{
    public function testTheBrandWordHostnamesAndLegalNameInText(): void
    {
        $this->assertSame('Aicountly helps.', BrandCasing::text('AICOUNTLY helps.'));
        $this->assertSame('Aicountly Smart Books and Aicountly Billing', BrandCasing::text('AICountly Smart Books and AiCountly Billing'));
        $this->assertSame("Aicountly's #Aicountly Aicountly-branded cover", BrandCasing::text("AICOUNTLY's #AICOUNTLY AICOUNTLY-branded cover"));
        $this->assertSame('Visit aicountly.com or books.aicountly.com; mail support@aicountly.com', BrandCasing::text('Visit AICOUNTLY.com or Books.AICOUNTLY.COM; mail support@AICOUNTLY.COM'));
        $this->assertSame('Aicountly Interactive Services Private Limited', BrandCasing::text('AICOUNTLY INTERACTIVE SERVICES PRIVATE LIMITED'));
        $this->assertSame('© Aicountly Interactive Services Private Limited', BrandCasing::text('© AICOUNTLY Interactive Services Pvt. Ltd.'));
        $this->assertSame('Aicountly Interactive Services Private Limited', BrandCasing::text('Aicountly Interactive Services Private Limited'));
    }

    public function testIdentifiersHandlesAndUrlPathsStay(): void
    {
        foreach ([
            'AICOUNTLY_PUBLIC_SITE_BASE_URL',
            'MyAICOUNTLYClone',
            'follow @AICOUNTLY',
            'aicountly-gst-guide',
            'see /blogs/AICOUNTLY-guide',
            'Aicountly at aicountly.com',
        ] as $s) {
            $this->assertSame($s, BrandCasing::text($s), $s);
        }
        $this->assertSame(
            'Read https://aicountly.com/Blogs/AICOUNTLY-Guide?ref=AICOUNTLY#AICOUNTLY today, with Aicountly.',
            BrandCasing::text('Read https://AICOUNTLY.com/Blogs/AICOUNTLY-Guide?ref=AICOUNTLY#AICOUNTLY today, with AICOUNTLY.')
        );
        $this->assertSame('https://YouTube.com/@AICOUNTLY', BrandCasing::text('https://YouTube.com/@AICOUNTLY'), 'other hosts and handles are left alone');
    }

    public function testHtmlChangesOnlyTextAndReadableAttributes(): void
    {
        $in = '<p class="AICOUNTLY" id="AICOUNTLY" data-title="AICOUNTLY">Welcome to AICOUNTLY. '
            . '<a href="https://AICOUNTLY.com/AICOUNTLY" title="AICOUNTLY home">AICOUNTLY.com</a> '
            . "<img src='/media/AICOUNTLY.png' alt='AICOUNTLY logo'> <span aria-label=\"AICOUNTLY\">x</span></p>"
            . '<pre>AICOUNTLY</pre><code>AICOUNTLY_X</code><script type="application/ld+json">{"name":"AICOUNTLY"}</script>'
            . '<style>.AICOUNTLY{}</style><!-- AICOUNTLY -->';
        $want = '<p class="AICOUNTLY" id="AICOUNTLY" data-title="AICOUNTLY">Welcome to Aicountly. '
            . '<a href="https://AICOUNTLY.com/AICOUNTLY" title="Aicountly home">aicountly.com</a> '
            . "<img src='/media/AICOUNTLY.png' alt='Aicountly logo'> <span aria-label=\"Aicountly\">x</span></p>"
            . '<pre>AICOUNTLY</pre><code>AICOUNTLY_X</code><script type="application/ld+json">{"name":"AICOUNTLY"}</script>'
            . '<style>.AICOUNTLY{}</style><!-- AICOUNTLY -->';
        $this->assertSame($want, BrandCasing::html($in));
        $this->assertSame('<p>R&amp;D at Aicountly &mdash; ok</p>', BrandCasing::html('<p>R&amp;D at AICOUNTLY &mdash; ok</p>'));
    }

    public function testMarkdownKeepsCodeAndLinkTargets(): void
    {
        $this->assertSame(
            "# Aicountly guide\n[Aicountly Billing](https://AICOUNTLY.com/AICOUNTLY-billing) `AICOUNTLY` \n```\nAICOUNTLY\n```",
            BrandCasing::markdown("# AICOUNTLY guide\n[AICOUNTLY Billing](https://AICOUNTLY.com/AICOUNTLY-billing) `AICOUNTLY` \n```\nAICOUNTLY\n```")
        );
    }

    public function testJsonLeavesIdentifierKeysAlone(): void
    {
        $in = [
            '@type' => 'Organization', 'name' => 'AICOUNTLY', 'url' => 'https://AICOUNTLY.com', 'slug' => 'AICOUNTLY',
            'product_slug' => 'AICOUNTLY', 'external_key' => 'AICOUNTLY-1', 'media_urls' => ['https://x/AICOUNTLY.png'],
            'steps' => [['title' => 'Open AICOUNTLY', 'description' => '<p>Click <b>AICOUNTLY</b></p>']],
            'hashtags' => ['#AICOUNTLY'], 'count' => 3, 'ok' => true, 'none' => null,
        ];
        $out = BrandCasing::json($in);
        $this->assertSame('Aicountly', $out['name']);
        $this->assertSame('Open Aicountly', $out['steps'][0]['title']);
        $this->assertSame('<p>Click <b>Aicountly</b></p>', $out['steps'][0]['description']);
        $this->assertSame(['#Aicountly'], $out['hashtags']);
        foreach (['@type', 'url', 'slug', 'product_slug', 'external_key', 'media_urls', 'count', 'ok', 'none'] as $k) {
            $this->assertSame($in[$k], $out[$k], $k);
        }

        $raw = json_encode(['title' => 'AICOUNTLY 1.1', 'url' => 'https://AICOUNTLY.com/x']);
        $this->assertSame('{"title":"Aicountly 1.1","url":"https://AICOUNTLY.com/x"}', BrandCasing::jsonString($raw));
        $this->assertSame('not json AICOUNTLY', BrandCasing::jsonString('not json AICOUNTLY'), 'invalid JSON is left as stored');
        $clean = '{"title":"Aicountly"}';
        $this->assertSame($clean, BrandCasing::jsonString($clean), 'untouched documents keep their exact bytes');
    }

    public function testEveryFormIsIdempotent(): void
    {
        $samples = [
            'AICOUNTLY at AICOUNTLY.com — AICOUNTLY INTERACTIVE SERVICES PVT LTD, https://AICOUNTLY.com/AICOUNTLY',
            '<p title="AICOUNTLY">AICOUNTLY <a href="/AICOUNTLY">AICountly</a></p><code>AICOUNTLY</code>',
            "AICOUNTLY [x](/AICOUNTLY) `AICOUNTLY`",
        ];
        foreach ($samples as $s) {
            foreach (['text', 'html', 'markdown'] as $fn) {
                $once = BrandCasing::$fn($s);
                $this->assertSame($once, BrandCasing::$fn($once), "{$fn}: {$s}");
            }
        }
        $json = (string) BrandCasing::jsonString(json_encode(['a' => $samples[0], 'b' => [$samples[1]]]));
        $this->assertSame($json, BrandCasing::jsonString($json));
        $this->assertFalse(BrandCasing::mayNeed('Aicountly at aicountly.com, Aicountly Interactive Services Private Limited'));
        $this->assertFalse(BrandCasing::mayNeed('Nothing about the brand here.'));
    }
}
