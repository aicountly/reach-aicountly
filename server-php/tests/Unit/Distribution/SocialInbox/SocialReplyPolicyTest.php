<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\SocialInbox;

use App\Libraries\Distribution\SocialInbox\SocialReplyPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Distribution\SocialInbox\SocialReplyPolicy
 *
 * @internal
 */
final class SocialReplyPolicyTest extends CIUnitTestCase
{
    public function testTheSignOffIsAddedAndHtmlStripped(): void
    {
        $r = SocialReplyPolicy::finalize('<b>Thanks</b> for the kind words!', 'linkedin');
        $this->assertTrue($r['ok']);
        $this->assertSame('Thanks for the kind words! — AICOUNTLY team', $r['text']);

        $r = SocialReplyPolicy::finalize('Glad it helps. — AICOUNTLY team', 'x');
        $this->assertSame('Glad it helps. — AICOUNTLY team', $r['text'], 'not signed twice');
    }

    public function testLengthLimitsPerNetwork(): void
    {
        $long = str_repeat('a', 270);
        $this->assertFalse(SocialReplyPolicy::finalize($long, 'x')['ok'], 'X: 280 including the sign-off');
        $this->assertStringContainsString('the limit for x is 280', (string) SocialReplyPolicy::finalize($long, 'x')['reason']);
        $this->assertTrue(SocialReplyPolicy::finalize($long, 'linkedin')['ok']);
        $this->assertFalse(SocialReplyPolicy::finalize(str_repeat('b', 700), 'facebook')['ok']);
        $this->assertFalse(SocialReplyPolicy::finalize('   ', 'x')['ok']);
    }

    public function testPersonalDataAndForeignLinksAreNeverPosted(): void
    {
        foreach ([
            'Your PAN ABCDE1234F is linked.'          => 'PAN',
            'Aadhaar 1234 5678 9012 noted.'           => 'Aadhaar',
            'Call us on +91 98765 43210.'             => 'phone',
            'Write to asha.verma@gmail.com.'          => 'e-mail',
            'See https://competitor.example/offer.'   => 'links to competitor.example',
            'Visit bit.ly/x for more.'                => 'links to bit.ly',
        ] as $text => $why) {
            $r = SocialReplyPolicy::finalize($text, 'linkedin');
            $this->assertFalse($r['ok'], $text);
            $this->assertStringContainsString($why, (string) $r['reason'], $text);
        }
        $ok = SocialReplyPolicy::finalize('Email support@aicountly.com or see https://aicountly.com/help and www.aicountly.com.', 'linkedin');
        $this->assertTrue($ok['ok'], (string) $ok['reason']);
    }

    public function testFixedTextsFitEveryNetworkAndQuoteTheTicket(): void
    {
        foreach (['comment', 'message'] as $kind) {
            $ack = SocialReplyPolicy::complaintAck('TCK-2026-00042', $kind);
            $this->assertStringContainsString('TCK-2026-00042', $ack);
            $r = SocialReplyPolicy::finalize($ack, 'x');
            $this->assertTrue($r['ok'], $kind . ': ' . $r['reason']);
            $this->assertSame($ack, $r['text']);
            $nudge = SocialReplyPolicy::privacyNudge($kind);
            $this->assertTrue(SocialReplyPolicy::finalize($nudge, 'x')['ok'], $kind);
        }
        $this->assertStringContainsString('direct message', SocialReplyPolicy::complaintAck('TCK-1', 'comment'), 'public complaints are moved to DM');
        $this->assertStringContainsString('no account details here', SocialReplyPolicy::complaintAck('TCK-1', 'comment'));
    }
}
