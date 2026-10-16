<?php

namespace App\Models;

class ConsoleSyncLogModel extends ReachModel
{
    protected $table         = 'reach_console_sync_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'event_type', 'payload', 'response_status', 'response_body',
        'ok', 'error_message', 'attempted_at',
        // Central-audit outbox (migration 2026-10-14-100002), written by ConsoleAuditClient.
        'external_id', 'status', 'attempts', 'next_attempt_at', 'event', 'console_audit_id',
    ];

    protected array $casts = [
        'payload'       => '?json-array',
        'response_body' => '?json-array',
        'event'         => '?json-array',
    ];
}
