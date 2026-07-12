<?php

namespace App\Models;

use CodeIgniter\Model;

class JobModel extends Model
{
    protected $table         = 'reach_jobs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'job_uuid', 'job_type', 'queue', 'status', 'priority',
        'payload_json', 'result_json', 'error_message',
        'attempts', 'max_attempts',
        'available_at', 'scheduled_at', 'reserved_at', 'started_at',
        'completed_at', 'lease_expires_at',
        'worker_id', 'progress', 'progress_message',
        'idempotency_key', 'request_id', 'correlation_id',
        'enqueued_by_user_id', 'enqueued_actor_type',
    ];
}
