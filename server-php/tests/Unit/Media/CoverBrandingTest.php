<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use App\Libraries\Ai\Images\CoverPromptBuilder;
use App\Libraries\Media\CoverBranding;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Media\CoverBranding
 * @covers \App\Libraries\Ai\Images\CoverPromptBuilder
 */
final class CoverBrandingTest extends CIUnitTestCase
{
    private static function png(int $w, int $h, array $rgb): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgbAt(string $binary, int $x, int $y): array
    {
        $im = imagecreatefromstring($binary);
        $c  = imagecolorat($im, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    public function testTheOfficialLogoIsBundled(): void
    {
        $this->assertFileExists(CoverBranding::logoPath());
        $info = getimagesize(CoverBranding::logoPath());
        $this->assertSame('image/png', $info['mime']);
        $this->assertNull((new CoverBranding())->unavailableReason());
    }

    public function testTheMarkSitsBottomRightOnAWhitePlateOnDarkAndLightCovers(): void
    {
        foreach ([[7, 26, 51], [234, 248, 230]] as $bg) {
            $out = (new CoverBranding())->apply(self::png(1536, 1024, $bg));

            $this->assertTrue($out['branded'], (string) $out['reason']);
            $this->assertNotSame(self::png(1536, 1024, $bg), $out['bytes']);
            // Top-left untouched.
            $this->assertSame($bg, self::rgbAt($out['bytes'], 20, 20));
            // The plate's padding (inside the mark, outside the logo) is near-white.
            $margin = (int) round(1536 * CoverBranding::MARGIN_RATIO);
            $markW  = (int) round(1536 * CoverBranding::MARK_WIDTH_RATIO);
            [$r, $g, $b] = self::rgbAt($out['bytes'], 1536 - $margin - (int) ($markW / 2), 1024 - $margin - 6);
            $this->assertGreaterThan(235, min($r, $g, $b), 'white plate behind the logo');
            [$w, $h] = getimagesizefromstring($out['bytes']);
            $this->assertSame([1536, 1024], [$w, $h]);
        }
    }

    public function testItFailsGracefully(): void
    {
        $this->assertSame('not_an_image', (new CoverBranding())->apply('not an image')['reason']);
        $this->assertFalse((new CoverBranding())->apply('not an image')['branded']);
        $this->assertSame('image_too_small_for_mark', (new CoverBranding())->apply(self::png(120, 60, [0, 0, 0]))['reason']);
        $missing = (new CoverBranding('/nonexistent/logo.png'))->apply(self::png(800, 600, [0, 0, 0]));
        $this->assertSame('logo_unavailable', $missing['reason']);
        $this->assertFalse($missing['branded']);
    }

    public function testTheBadgeHasATransparentSurround(): void
    {
        $png = (new CoverBranding())->badgePng(320);
        $this->assertNotNull($png);
        $im = imagecreatefromstring($png);
        $this->assertSame(320, imagesx($im));
        $this->assertSame(127, (imagecolorat($im, 0, 0) >> 24) & 0x7F, 'rounded corner is transparent');
        $this->assertLessThan(10, (imagecolorat($im, 160, 4) >> 24) & 0x7F, 'the plate itself is opaque');
    }

    public function testArticlePromptsCarryTheKeyPointsAndBrandStyle(): void
    {
        $points = CoverPromptBuilder::keyPointsFrom("Intro\n## Who must file MSME-1\n## Half-yearly due dates\n## FAQ\n### Penalties for late filing\n");
        $this->assertSame(['Who must file MSME-1', 'Half-yearly due dates', 'Penalties for late filing'], $points);

        $prompt = (new CoverPromptBuilder())->build('MSME-1 half-yearly return', 'Companies report dues to micro and small suppliers.', null, null, $points);
        $this->assertStringContainsString('MSME-1 half-yearly return', $prompt);
        $this->assertStringContainsString('Who must file MSME-1; Half-yearly due dates', $prompt);
        $this->assertStringContainsString('green and white brand palette', $prompt);
        $this->assertStringContainsString('no logos', $prompt, 'the model never draws the brand; it is composited');
        $this->assertStringContainsString('bottom-right corner calm', $prompt);

        $this->assertSame(['Step one'], CoverPromptBuilder::keyPointsFrom(null, '<h2>Step <em>one</em></h2><h2>Conclusion</h2>'));
    }
}
