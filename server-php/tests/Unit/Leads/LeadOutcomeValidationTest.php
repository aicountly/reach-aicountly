<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Libraries\Leads\LeadOutcomeIngestService;
use App\Libraries\Leads\LeadOutcomeSummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Pure validation / normalisation of Engage outcome events and summary period bounds.
 *
 * @internal
 */
final class LeadOutcomeValidationTest extends TestCase
{
    private static function event(array $over = []): array
    {
        return array_merge([
            'event_id'      => 'evt-0001',
            'lead_code'     => 'LD-000041',
            'reach_lead_id' => 41,
            'email_sha256'  => null,
            'stage'         => 'converted',
            'sales_status'  => 'Won',
            'product'       => 'books',
            'value_inr'     => '4999.5',
            'occurred_at'   => '2026-10-17T10:15:00+05:30',
            'seller_code'   => 'slr-000123',
            'campaign_code' => 'REACH-7',
            'source_portal' => 'reach.aicountly.org',
        ], $over);
    }

    public function testAValidEventIsNormalised(): void
    {
        $v = LeadOutcomeIngestService::validateEvent(self::event());
        $this->assertTrue($v['ok']);
        $e = $v['event'];
        $this->assertSame('4999.50', $e['value_inr']);
        $this->assertSame('2026-10-17T04:45:00+00:00', $e['occurred_at'], 'stored in UTC');
        $this->assertSame('SLR-000123', $e['seller_code']);
        $this->assertSame('won', $e['sales_status']);
        $this->assertSame(41, $e['reach_lead_id']);
    }

    public function testNumericValueAndStringLeadIdAreAccepted(): void
    {
        $v = LeadOutcomeIngestService::validateEvent(self::event(['value_inr' => 1200, 'reach_lead_id' => '41']));
        $this->assertTrue($v['ok']);
        $this->assertSame('1200.00', $v['event']['value_inr']);
        $this->assertSame(41, $v['event']['reach_lead_id']);
    }

    /** @return iterable<string, array{0:array<string,mixed>, 1:string}> */
    public static function invalidEvents(): iterable
    {
        yield 'no event_id'          => [['event_id' => null], 'event_id is required'];
        yield 'bad event_id chars'   => [['event_id' => 'evt 1'], 'event_id may contain'];
        yield 'no stage'             => [['stage' => ''], 'stage is required'];
        yield 'stage not a slug'     => [['stage' => 'Proposal Sent'], 'stage must be'];
        yield 'negative value'       => [['value_inr' => '-1.00'], 'value_inr'];
        yield 'three decimals'       => [['value_inr' => '1.005'], 'value_inr'];
        yield 'value as words'       => [['value_inr' => 'ten'], 'value_inr'];
        yield 'relative time'        => [['occurred_at' => 'yesterday'], 'occurred_at'];
        yield 'missing time'         => [['occurred_at' => null], 'occurred_at is required'];
        yield 'bad hash'             => [['email_sha256' => 'abc'], 'email_sha256'];
        yield 'zero lead id'         => [['reach_lead_id' => 0], 'reach_lead_id'];
        yield 'no identifier at all' => [['lead_code' => null, 'reach_lead_id' => null, 'email_sha256' => null], 'one of lead_code'];
        yield 'array as string'      => [['product' => ['x']], 'product must be a string'];
        yield 'overlong seller code' => [['seller_code' => str_repeat('S', 33)], 'seller_code is longer'];
    }

    /** @dataProvider invalidEvents */
    public function testInvalidEventsAreRejectedWithAReason(array $over, string $reason): void
    {
        $v = LeadOutcomeIngestService::validateEvent(self::event($over));
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString($reason, $v['error']);
    }

    public function testANonObjectIsRejected(): void
    {
        $this->assertFalse(LeadOutcomeIngestService::validateEvent('evt')['ok']);
        $this->assertFalse(LeadOutcomeIngestService::validateEvent([1, 2])['ok']);
    }

    public function testEmailHashMatchesWhatEngageIsAskedToSend(): void
    {
        $this->assertSame(hash('sha256', 'asha@example.test'), LeadOutcomeIngestService::emailHash('  Asha@Example.TEST '));
    }

    public function testMoneyKeepsDigitsExactly(): void
    {
        $this->assertSame('0.50', LeadOutcomeIngestService::money('0.5'));
        $this->assertSame('123456789012.99', LeadOutcomeIngestService::money('123456789012.99'));
        $this->assertSame('7.00', LeadOutcomeIngestService::money('007'));
    }

    public function testDateOnlyBoundsCoverWholeIstDays(): void
    {
        [$start, $end] = LeadOutcomeSummary::bounds('2026-10-16', '2026-10-16');
        $this->assertSame('2026-10-16T00:00:00+05:30', $start->format('c'));
        $this->assertSame('2026-10-17T00:00:00+05:30', $end->format('c'));
    }

    public function testDateTimeBoundsAreUsedAsGiven(): void
    {
        [$start, $end] = LeadOutcomeSummary::bounds('2026-10-01T00:00:00Z', '2026-10-08T00:00:00Z');
        $this->assertSame('2026-10-01T00:00:00+00:00', $start->format('c'));
        $this->assertSame('2026-10-08T00:00:00+00:00', $end->format('c'));
    }

    public function testInvalidBoundsAreRefused(): void
    {
        foreach ([['2026-02-30', '2026-03-01'], ['last week', '2026-03-01'], ['2026-10-10', '2026-10-09']] as [$f, $t]) {
            try {
                LeadOutcomeSummary::bounds($f, $t);
                $this->fail("{$f}..{$t} should be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
