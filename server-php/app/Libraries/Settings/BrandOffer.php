<?php

declare(strict_types=1);

namespace App\Libraries\Settings;

/**
 * What Aicountly offers a new customer, in the words the vetting model may
 * accept. Without it Gemini (rightly) rejects "sign up free" as an
 * unsupported claim. One setting (brand.offer) feeds the video-script and
 * social-post vetting context and the reach-command brief.
 */
final class BrandOffer
{
    public const DEFAULT = 'Signing up at aicountly.com is free: the Free plan is free to get started with the launch apps (Smart Books accounting, GST billing & invoicing, Inventory, Notes, Drive) and Manage. There is no free trial. Say "sign up free" or "free to get started"; never mention trials, usage limits, caps or prices. '
        . self::ACADEMY;

    /**
     * The Aicountly Academy line of the default offer (also part of the
     * runbook text for a stored brand.offer). Courses are applied for, not
     * signed up to free: no fee amount, discount or "free" claim is supported.
     */
    public const ACADEMY = 'Aicountly Academy courses (Aicountly Edge, the long-term programme, and Aicountly Aspire, short-term courses and workshops) are open for applications at aicountly.com/academy: say "apply now" or "talk to admissions"; fees are on the course page or shared by admissions — never state a fee amount, a discount, "free" or any placement, job or salary promise.';

    /** The offer text, or '' when the owner cleared it (then no offer claim is supported). */
    public static function text(): string
    {
        return trim((string) ReachConfig::get('brand.offer', self::DEFAULT));
    }
}
