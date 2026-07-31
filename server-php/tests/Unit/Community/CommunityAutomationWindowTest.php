<?php

namespace Tests\Unit\Community;

use App\Libraries\Community\CommunityAutomationWindow;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CommunityAutomationWindowTest extends TestCase
{
    /** UTC 03:30 = IST 09:00 (IST is UTC+5:30) — window opens exactly here. */
    public function testOpensAtNineAmIst(): void
    {
        $this->assertTrue(CommunityAutomationWindow::isOpenAt(new DateTimeImmutable('2026-07-31T03:30:00+00:00')));
    }

    /** UTC 03:29:59 = IST 08:59:59 — one second before opening. */
    public function testClosedOneSecondBeforeOpening(): void
    {
        $this->assertFalse(CommunityAutomationWindow::isOpenAt(new DateTimeImmutable('2026-07-31T03:29:59+00:00')));
    }

    /** UTC 13:29 = IST 18:59 — still open. */
    public function testOpenOneMinuteBeforeClosing(): void
    {
        $this->assertTrue(CommunityAutomationWindow::isOpenAt(new DateTimeImmutable('2026-07-31T13:29:00+00:00')));
    }

    /** UTC 13:30 = IST 19:00 — window closes here (exclusive). */
    public function testClosedExactlyAtNineteenHundredIst(): void
    {
        $this->assertFalse(CommunityAutomationWindow::isOpenAt(new DateTimeImmutable('2026-07-31T13:30:00+00:00')));
    }

    /** UTC midnight = IST 05:30 — deep in the closed overnight period. */
    public function testClosedOvernight(): void
    {
        $this->assertFalse(CommunityAutomationWindow::isOpenAt(new DateTimeImmutable('2026-07-31T00:00:00+00:00')));
    }

    public function testMiddayIstIsOpen(): void
    {
        $this->assertTrue(CommunityAutomationWindow::isOpenAt(new DateTimeImmutable('2026-07-31T08:00:00+00:00'))); // IST 13:30
    }

    public function testIndependentOfServerTimezoneOffset(): void
    {
        // Same instant expressed with a different UTC offset must give the same answer.
        $utc = new DateTimeImmutable('2026-07-31T08:00:00+00:00');
        $ist = new DateTimeImmutable('2026-07-31T13:30:00+05:30');
        $this->assertSame(
            CommunityAutomationWindow::isOpenAt($utc),
            CommunityAutomationWindow::isOpenAt($ist)
        );
    }
}
