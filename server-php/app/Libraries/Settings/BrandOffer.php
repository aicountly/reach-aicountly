<?php

declare(strict_types=1);

namespace App\Libraries\Settings;

/**
 * What AICOUNTLY offers a new customer, in the words the vetting model may
 * accept. Without it Gemini (rightly) rejects "sign up free" as an
 * unsupported claim. One setting (brand.offer) feeds the video-script and
 * social-post vetting context and the reach-command brief.
 */
final class BrandOffer
{
    public const DEFAULT = 'Signing up at aicountly.com is free: the Free plan is free to get started with the launch apps (Smart Books accounting, GST billing & invoicing, Inventory, Notes, Drive) and Manage. There is no free trial. Say "sign up free" or "free to get started"; never mention trials, usage limits, caps or prices.';

    /** The offer text, or '' when the owner cleared it (then no offer claim is supported). */
    public static function text(): string
    {
        return trim((string) ReachConfig::get('brand.offer', self::DEFAULT));
    }
}
