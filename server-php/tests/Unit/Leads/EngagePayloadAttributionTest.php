<?php

declare(strict_types=1);

namespace Tests\Unit\Leads;

use App\Controllers\Api\V1\LeadController;
use App\Libraries\EngageClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Seller `ref` and UTM / landing data: captured from the public form body and
 * sent to Engage in the lead push payload.
 *
 * @internal
 */
final class EngagePayloadAttributionTest extends CIUnitTestCase
{
    public function testCaptureReadsRefUtmAndLandingFields(): void
    {
        $out = LeadController::attributionFields([
            'ref'          => ' slr-000123 ',
            'utm_source'   => 'google',
            'utm'          => ['medium' => 'cpc', 'campaign' => 'diwali-gst', 'source' => 'ignored-flat-wins'],
            'utm_term'     => "gst\nfiling",
            'landing_url'  => 'https://www.aicountly.com/gst?ref=SLR-000123',
            'referrer'     => 'https://www.google.com/',
        ]);

        $this->assertSame([
            'ref_code'     => 'SLR-000123',
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'diwali-gst',
            'utm_term'     => 'gst filing',
            'landing_url'  => 'https://www.aicountly.com/gst?ref=SLR-000123',
            'referrer_url' => 'https://www.google.com/',
        ], $out);
    }

    public function testMalformedTrackingValuesAreDroppedNotFatal(): void
    {
        $out = LeadController::attributionFields([
            'ref'         => 'SLR 000123; DROP',
            'utm_source'  => ['x'],
            'landing_url' => 'javascript:alert(1)',
            'referrer'    => 'not a url',
            'utm_content' => str_repeat('c', 300),
        ]);

        $this->assertSame(['utm_content' => str_repeat('c', 240)], $out);
    }

    public function testEngagePayloadCarriesRefAndUtmInReachMeta(): void
    {
        $body = (new EngageClient())->buildBody([
            'id' => 41, 'name' => 'Asha Rao', 'email' => 'asha@example.test', 'priority' => 'normal',
            'source_kind' => 'landing', 'landing_page_id' => 3,
            'ref_code' => 'SLR-000123', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'diwali-gst',
            'utm_content' => null, 'utm_term' => '', 'landing_url' => 'https://www.aicountly.com/gst', 'referrer_url' => null,
        ]);

        $this->assertSame('SLR-000123', $body['ref']);
        $this->assertSame([
            'reach_lead_id'   => 41,
            'source_kind'     => 'landing',
            'landing_page_id' => 3,
            'ref'             => 'SLR-000123',
            'utm_source'      => 'google',
            'utm_medium'      => 'cpc',
            'utm_campaign'    => 'diwali-gst',
            'landing_url'     => 'https://www.aicountly.com/gst',
        ], $body['reach_meta']);
    }

    public function testNoRefMeansNoRefKey(): void
    {
        $body = (new EngageClient())->buildBody(['id' => 5, 'name' => 'X', 'priority' => 'normal']);
        $this->assertArrayNotHasKey('ref', $body);
        $this->assertSame(['reach_lead_id' => 5], $body['reach_meta']);
    }
}
