<?php

declare(strict_types=1);

namespace App\Libraries\Media;

use App\Libraries\Settings\ReachConfig;

/**
 * Puts the AICOUNTLY logo on a cover (or a video still / thumbnail) as a
 * corner mark.
 *
 * The logo is the official asset from aicountly.com (assests/images/ai-logo.png,
 * bundled at app/Assets/brand/aicountly-logo.png so branding never depends on
 * a network fetch). It is drawn on a near-opaque (≈96 %) white rounded plate in the
 * bottom-right corner — the logo's navy wordmark and green arrow stay legible
 * on a dark illustration as well as a light one. Size and padding scale with
 * the image width, so every cover carries the same mark.
 *
 * Generated art is prompted with "no logos, no text": the model is never asked
 * to draw the brand (it would misspell it); the brand is added here.
 *
 * Fails gracefully: without GD, without the logo file, or on an undecodable
 * image, apply() returns the input unchanged with branded=false and a reason.
 * Branding must never be the reason a cover is lost.
 */
final class CoverBranding
{
    /** Corner mark width as a share of the image width. */
    public const MARK_WIDTH_RATIO = 0.15;
    /** Gap between the mark and the image edges, as a share of the image width. */
    public const MARGIN_RATIO = 0.022;

    private ?\GdImage $logo = null;

    public function __construct(private readonly ?string $logoPath = null)
    {
    }

    public static function logoPath(): string
    {
        return APPPATH . 'Assets/brand/aicountly-logo.png';
    }

    /** covers.brand.logo_enabled (default on). */
    public static function enabled(): bool
    {
        try {
            return (bool) ReachConfig::get('covers.brand.logo_enabled', true);
        } catch (\Throwable) {
            return true;
        }
    }

    /** Null when branding can run; otherwise why it cannot. */
    public function unavailableReason(): ?string
    {
        if (! extension_loaded('gd')) {
            return 'gd_unavailable';
        }

        return $this->loadLogo() === null ? 'logo_unavailable' : null;
    }

    /**
     * @return array{bytes: string, branded: bool, reason: ?string}
     */
    public function apply(string $binary): array
    {
        if (! self::enabled()) {
            return ['bytes' => $binary, 'branded' => false, 'reason' => 'branding_disabled'];
        }
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            return ['bytes' => $binary, 'branded' => false, 'reason' => $reason];
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return ['bytes' => $binary, 'branded' => false, 'reason' => 'not_an_image'];
        }
        imagepalettetotruecolor($image);

        $width  = imagesx($image);
        $height = imagesy($image);
        $markW  = (int) max(96, min(480, round($width * self::MARK_WIDTH_RATIO)));
        $badge  = $this->badge($markW);
        if ($badge === null) {
            imagedestroy($image);

            return ['bytes' => $binary, 'branded' => false, 'reason' => 'logo_unavailable'];
        }

        $bw     = imagesx($badge);
        $bh     = imagesy($badge);
        $margin = (int) max(10, round($width * self::MARGIN_RATIO));
        if ($bw + 2 * $margin > $width || $bh + 2 * $margin > $height) {
            imagedestroy($badge);
            imagedestroy($image);

            return ['bytes' => $binary, 'branded' => false, 'reason' => 'image_too_small_for_mark'];
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);
        imagecopyresampled($image, $badge, $width - $bw - $margin, $height - $bh - $margin, 0, 0, $bw, $bh, $bw, $bh);
        imagedestroy($badge);

        ob_start();
        $ok    = imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        if (! $ok || $bytes === '') {
            return ['bytes' => $binary, 'branded' => false, 'reason' => 'encode_failed'];
        }

        return ['bytes' => $bytes, 'branded' => true, 'reason' => null];
    }

    /**
     * The corner mark on its own as a PNG with a transparent surround — the
     * video timeline overlays this on every scene.
     */
    public function badgePng(int $width = 320): ?string
    {
        if ($this->unavailableReason() !== null) {
            return null;
        }
        $badge = $this->badge($width);
        if ($badge === null) {
            return null;
        }
        ob_start();
        $ok    = imagepng($badge, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($badge);

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /** White rounded plate (≈96 % opaque) with the logo centred on it. */
    private function badge(int $width): ?\GdImage
    {
        $logo = $this->loadLogo();
        if ($logo === null) {
            return null;
        }

        $pad    = (int) max(6, round($width * 0.09));
        $logoW  = $width - 2 * $pad;
        $logoH  = (int) max(1, round($logoW * imagesy($logo) / max(1, imagesx($logo))));
        $height = $logoH + 2 * $pad;
        $radius = (int) max(4, round($width * 0.08));

        $plate = imagecreatetruecolor($width, $height);
        imagealphablending($plate, false);
        imagesavealpha($plate, true);
        imagefill($plate, 0, 0, imagecolorallocatealpha($plate, 0, 0, 0, 127));

        // Drawn with blending off, so overlapping shapes do not stack opacity.
        $white = imagecolorallocatealpha($plate, 255, 255, 255, 5);
        imagefilledrectangle($plate, $radius, 0, $width - 1 - $radius, $height - 1, $white);
        imagefilledrectangle($plate, 0, $radius, $width - 1, $height - 1 - $radius, $white);
        foreach ([[$radius, $radius], [$width - 1 - $radius, $radius], [$radius, $height - 1 - $radius], [$width - 1 - $radius, $height - 1 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($plate, $cx, $cy, 2 * $radius, 2 * $radius, $white);
        }

        imagealphablending($plate, true);
        imagecopyresampled($plate, $logo, $pad, $pad, 0, 0, $logoW, $logoH, imagesx($logo), imagesy($logo));

        return $plate;
    }

    private function loadLogo(): ?\GdImage
    {
        if ($this->logo !== null) {
            return $this->logo;
        }
        if (! extension_loaded('gd')) {
            return null;
        }
        $path = $this->logoPath ?? self::logoPath();
        if (! is_file($path)) {
            return null;
        }
        $logo = @imagecreatefromstring((string) file_get_contents($path));
        if ($logo === false) {
            return null;
        }
        imagepalettetotruecolor($logo);
        imagealphablending($logo, false);
        imagesavealpha($logo, true);

        return $this->logo = $logo;
    }
}
