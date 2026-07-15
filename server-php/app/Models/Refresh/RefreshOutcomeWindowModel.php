<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshOutcomeWindowModel extends Model
{
    protected $table      = 'reach_refresh_outcome_windows';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;

    protected $allowedFields = [
        'publication_link_id', 'content_identity_id', 'baseline_from', 'baseline_to',
        'post_from', 'post_to', 'measurement_status',
    ];
}
