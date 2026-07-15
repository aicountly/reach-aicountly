<?php

declare(strict_types=1);

namespace App\Models\Refresh;

use CodeIgniter\Model;

class RefreshContentVersionLinkModel extends Model
{
    protected $table      = 'reach_refresh_content_version_links';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'workflow_id', 'content_version_id', 'blog_version_id',
        'community_answer_version_id', 'video_script_version_id',
        'generation_artifact_id', 'version_status',
    ];
}
