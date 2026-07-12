<?php

namespace Tests\Feature;

use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * B2 test #10 — audit log creation and correlation-id propagation.
 */
final class AuditLogTest extends DatabaseTestCase
{
    public function testAuditLoggerWritesRow(): void
    {
        Services::reset(true);
        $logger = Services::auditLogger();

        $logger->log(
            userId: 1,
            action: 'unit.test_event',
            entityType: 'test',
            entityId: 42,
            oldValue: ['before' => 'x'],
            newValue: ['after'  => 'y'],
        );

        $row = \Config\Database::connect()
            ->table('reach_audit_logs')
            ->where('action', 'unit.test_event')
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('test', $row['entity_type']);
        $this->assertSame(42, (int) $row['entity_id']);
    }
}
