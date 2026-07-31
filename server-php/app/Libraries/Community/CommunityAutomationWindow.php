<?php

namespace App\Libraries\Community;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The community bot-first automation window: bot identities may only create
 * new public-facing content (curated questions, generated answers, posted
 * comments) between 09:00 and 19:00 Asia/Kolkata — the hours real community
 * members are actually active, so bot activity blends into normal traffic
 * patterns rather than posting in bulk overnight. Purely read-only or
 * review actions (moderation hints, objection flags, risk escalation
 * recommendations) are not gated by this window since they never create new
 * public content.
 *
 * Pure and side-effect free (aside from reading the clock) specifically so
 * it can be unit tested by injecting a fixed instant.
 */
class CommunityAutomationWindow
{
    private const TIMEZONE    = 'Asia/Kolkata';
    private const OPEN_MINUTE  = 9 * 60;
    private const CLOSE_MINUTE = 19 * 60;

    public static function isOpenAt(DateTimeImmutable $instant): bool
    {
        $local = $instant->setTimezone(new DateTimeZone(self::TIMEZONE));
        $mins  = ((int) $local->format('H')) * 60 + (int) $local->format('i');

        return $mins >= self::OPEN_MINUTE && $mins < self::CLOSE_MINUTE;
    }

    public static function isOpenNow(): bool
    {
        return self::isOpenAt(new DateTimeImmutable('now'));
    }
}
