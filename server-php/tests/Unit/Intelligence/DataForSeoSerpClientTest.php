<?php

declare(strict_types=1);

namespace Tests\Unit\Intelligence;

use App\Libraries\Intelligence\Seo\DataForSeoException;
use App\Libraries\Intelligence\Seo\DataForSeoSerpClient;
use App\Libraries\Intelligence\Seo\SeoTrackerService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * DataForSEO Live/Advanced SERP client — request shape and response parsing,
 * against a fake transport (no network).
 */
final class DataForSeoSerpClientTest extends CIUnitTestCase
{
    /** @param list<array<string, mixed>> $items */
    public static function okBody(array $items, float $cost = 0.006): string
    {
        return (string) json_encode([
            'version' => '0.1.20240801', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost,
            'tasks_count' => 1, 'tasks_error' => 0,
            'tasks' => [[
                'id' => '10171234-1234-0066-0000-abcdef', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost,
                'result_count' => 1, 'path' => ['v3', 'serp', 'google', 'organic', 'live', 'advanced'],
                'data' => ['api' => 'serp', 'function' => 'live', 'se' => 'google', 'se_type' => 'organic'],
                'result' => [[
                    'keyword' => 'gst billing software', 'type' => 'organic', 'se_domain' => 'google.co.in',
                    'location_code' => 2356, 'language_code' => 'en', 'check_url' => 'https://www.google.co.in/search?q=gst+billing+software',
                    'datetime' => '2026-10-17 01:00:00 +00:00', 'se_results_count' => 41200000, 'items_count' => count($items),
                    'items' => $items,
                ]],
            ]],
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function sampleItems(): array
    {
        return [
            ['type' => 'paid', 'rank_group' => 1, 'rank_absolute' => 1, 'domain' => 'ads.example.com', 'url' => 'https://ads.example.com/'],
            ['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 2, 'domain' => 'vyaparapp.in', 'url' => 'https://vyaparapp.in/gst-billing-software', 'title' => 'Vyapar'],
            ['type' => 'people_also_ask', 'rank_group' => 1, 'rank_absolute' => 3, 'items' => []],
            ['type' => 'organic', 'rank_group' => 2, 'rank_absolute' => 4, 'domain' => 'www.zoho.com', 'url' => 'https://www.zoho.com/in/billing/'],
            ['type' => 'organic', 'rank_group' => 3, 'rank_absolute' => 5, 'domain' => 'books.zoho.com', 'url' => 'https://books.zoho.com/gst'],
            ['type' => 'organic', 'rank_group' => 4, 'rank_absolute' => 7, 'domain' => 'www.aicountly.com', 'url' => 'https://www.aicountly.com/billing'],
            ['type' => 'organic', 'rank_group' => 5, 'rank_absolute' => 9, 'domain' => 'aicountly.com', 'url' => 'https://aicountly.com/blog/gst'],
            ['type' => 'organic', 'rank_group' => 6, 'rank_absolute' => 12, 'domain' => 'notzoho.com', 'url' => 'https://notzoho.com/'],
        ];
    }

    public function testRequestShapeIsOneLiveTaskWithBasicAuth(): void
    {
        $captured = null;
        $client = new DataForSeoSerpClient('user@x.com', 's3cret', static function (string $url, string $body, array $headers, int $timeout) use (&$captured): array {
            $captured = compact('url', 'body', 'headers');

            return ['status' => 200, 'body' => self::okBody(self::sampleItems())];
        });

        $client->searchOrganic('gst billing software', 2356, 'en', 100);

        $this->assertSame('https://api.dataforseo.com/v3/serp/google/organic/live/advanced', $captured['url']);
        $this->assertContains('Authorization: Basic ' . base64_encode('user@x.com:s3cret'), $captured['headers']);
        $payload = json_decode($captured['body'], true);
        $this->assertCount(1, $payload, 'Live endpoints take one task per request.');
        $this->assertSame(['keyword' => 'gst billing software', 'location_code' => 2356, 'language_code' => 'en', 'device' => 'desktop', 'depth' => 100], $payload[0]);
    }

    public function testParseKeepsOnlyOrganicItemsAndNormalisesDomains(): void
    {
        $serp = DataForSeoSerpClient::parse('gst billing software', 200, self::okBody(self::sampleItems(), 0.0055));

        $this->assertSame(6, $serp['items_count']);
        $this->assertSame([2, 4, 5, 7, 9, 12], array_column($serp['items'], 'rank_absolute'));
        $this->assertSame('zoho.com', $serp['items'][1]['domain']);
        $this->assertSame(41200000, $serp['se_results_count']);
        $this->assertSame(0.0055, $serp['cost']);
    }

    public function testBestRanksMatchesSubdomainsButNotLookalikes(): void
    {
        $serp = DataForSeoSerpClient::parse('k', 200, self::okBody(self::sampleItems()));
        $best = SeoTrackerService::bestRanks($serp, 'aicountly.com', [
            ['id' => 1, 'name' => 'Zoho Books', 'domain' => 'zoho.com'],
            ['id' => 2, 'name' => 'Vyapar', 'domain' => 'vyaparapp.in'],
            ['id' => 3, 'name' => 'Tally', 'domain' => 'tallysolutions.com'],
        ]);

        $this->assertSame(7, $best['ours']['rank'], 'Our best (first) organic result.');
        $this->assertSame('https://www.aicountly.com/billing', $best['ours']['url']);
        $this->assertSame(4, $best['competitors'][1]['rank'], 'www.zoho.com counts for zoho.com; notzoho.com does not.');
        $this->assertSame(2, $best['competitors'][2]['rank']);
        $this->assertArrayNotHasKey(3, $best['competitors'], 'Absent from the SERP = no rank, never a guess.');

        $this->assertTrue(DataForSeoSerpClient::hostMatches('books.zoho.com', 'zoho.com'));
        $this->assertFalse(DataForSeoSerpClient::hostMatches('notzoho.com', 'zoho.com'));
    }

    public function testHttp401IsAnAuthErrorThatStopsTheRun(): void
    {
        try {
            DataForSeoSerpClient::parse('k', 401, '{"status_code":40100,"status_message":"You are not authorized to access this resource."}');
            $this->fail('expected exception');
        } catch (DataForSeoException $e) {
            $this->assertSame('auth', $e->category);
            $this->assertTrue($e->isFatalForRun());
        }
    }

    public function testTaskLevelErrorSurfacesTheProviderMessage(): void
    {
        $body = json_encode(['status_code' => 20000, 'status_message' => 'Ok.', 'tasks' => [
            ['status_code' => 40210, 'status_message' => 'Insufficient funds.', 'result' => null],
        ]]);

        try {
            DataForSeoSerpClient::parse('k', 200, (string) $body);
            $this->fail('expected exception');
        } catch (DataForSeoException $e) {
            $this->assertStringContainsString('Insufficient funds', $e->getMessage());
            $this->assertSame('billing', $e->category);
        }
    }

    public function testEmptyResultIsNotAnError(): void
    {
        $body = json_encode(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['keyword' => 'k', 'items' => null]]]]]);
        $serp = DataForSeoSerpClient::parse('k', 200, (string) $body);

        $this->assertSame([], $serp['items']);
    }

    public function testUnconfiguredClientNeverCallsTheNetwork(): void
    {
        $called = false;
        $client = new DataForSeoSerpClient(null, null, static function () use (&$called): array {
            $called = true;

            return ['status' => 200, 'body' => '{}'];
        });

        $this->assertFalse($client->isConfigured());
        $this->expectException(DataForSeoException::class);
        try {
            $client->searchOrganic('k', 2356, 'en', 100);
        } finally {
            $this->assertFalse($called);
        }
    }
}
