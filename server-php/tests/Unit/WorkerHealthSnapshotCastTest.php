<?php

namespace Tests\Unit;

use App\Models\WorkerHealthSnapshotModel;
use CodeIgniter\DataCaster\Cast\BooleanCast;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PostgreSQL returns booleans as 't'/'f'. Without a cast the worker-status page read 'f' as truthy
 * and showed a failed health ping (HTTP 503, browser down) as "Ok".
 *
 * @internal
 */
final class WorkerHealthSnapshotCastTest extends CIUnitTestCase
{
    public function testOkIsCastToBoolean(): void
    {
        $casts = (new \ReflectionProperty(WorkerHealthSnapshotModel::class, 'casts'))->getValue(new WorkerHealthSnapshotModel());
        $this->assertSame('boolean', $casts['ok'] ?? null);
        $this->assertFalse(BooleanCast::get('f'));
        $this->assertTrue(BooleanCast::get('t'));
    }
}
