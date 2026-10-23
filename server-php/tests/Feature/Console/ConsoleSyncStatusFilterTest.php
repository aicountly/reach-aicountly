<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Config\Database;
use Tests\Support\ApiTestCase;

/**
 * GET v1/admin/console-sync-status?result=&since_hours=: the "Ok / Errors
 * (last hour)" tiles open the events table narrowed with the same predicate
 * they counted with.
 *
 * @internal
 */
final class ConsoleSyncStatusFilterTest extends ApiTestCase
{
    private function event(bool $ok, string $when): void
    {
        Database::connect()->table('reach_console_sync_logs')->insert([
            'event_type' => 'audit', 'ok' => $ok, 'attempted_at' => $when, 'response_status' => $ok ? 200 : 500,
        ]);
    }

    /** @return array<string, mixed> */
    private function syncStatus(string $qs = ''): array
    {
        $res = $this->withHeaders($this->authAs('super_admin'))->call('GET', 'v1/admin/console-sync-status' . $qs);
        $this->assertSame(200, $res->response()->getStatusCode());

        return json_decode((string) $res->getJSON(), true)['data'];
    }

    public function testHourTilesOpenTheRowsTheyCounted(): void
    {
        $now = date('Y-m-d H:i:s');
        $old = date('Y-m-d H:i:s', strtotime('-3 hours'));
        $this->event(true, $now);
        $this->event(true, $old);
        $this->event(false, $now);
        $this->event(false, $now);
        $this->event(false, $old);

        $all = $this->syncStatus();
        $this->assertSame(5, $all['events_total']);
        $this->assertSame(2, $all['errors_last_hour']);
        $this->assertSame(1, $all['ok_last_hour']);

        $errors = $this->syncStatus('?result=error&since_hours=1');
        $this->assertSame($all['errors_last_hour'], $errors['events_total']);
        $this->assertCount(2, $errors['recent_events']);
        $this->assertSame($all['ok_last_hour'], $this->syncStatus('?result=ok&since_hours=1')['events_total']);
        // The tiles themselves stay unfiltered.
        $this->assertSame(2, $errors['errors_last_hour']);
        // Unknown values are ignored.
        $this->assertSame(5, $this->syncStatus('?result=maybe&since_hours=x')['events_total']);
    }
}
