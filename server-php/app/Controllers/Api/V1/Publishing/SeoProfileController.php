<?php

namespace App\Controllers\Api\V1\Publishing;

use App\Controllers\Api\V1\BaseApiController;
use App\Libraries\Publishing\Seo\SeoReadinessService;
use App\Libraries\AuditLogger;

class SeoProfileController extends BaseApiController
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function show(int $contentItemId): \CodeIgniter\HTTP\ResponseInterface
    {
        $profile = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        return $this->ok($profile ?? []);
    }

    public function update(int $contentItemId): \CodeIgniter\HTTP\ResponseInterface
    {
        $body = $this->request->getJSON(true) ?? [];

        $allowed = ['primary_keyword','meta_title','meta_description','slug','canonical_preference','robots_directive','focus_language'];
        $data    = array_intersect_key($body, array_flip($allowed));
        $data['updated_at'] = date('Y-m-d H:i:s');

        // Validate slug format
        if (!empty($data['slug']) && !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $data['slug'])) {
            return $this->error('Invalid slug format. Use lowercase letters, numbers, and hyphens only.', 422);
        }

        $exists = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)->countAllResults();

        if ($exists) {
            $this->db->table('reach_content_seo_profiles')
                ->where('content_item_id', $contentItemId)->update($data);
        } else {
            $data['content_item_id'] = $contentItemId;
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('reach_content_seo_profiles')->insert($data);
        }

        $actor = $this->request->actor ?? null;
        AuditLogger::log('seo.profile_updated', ['content_item_id' => $contentItemId], $actor?->id);

        return $this->ok(['saved' => true]);
    }

    public function evaluate(int $contentItemId): \CodeIgniter\HTTP\ResponseInterface
    {
        $result = (new SeoReadinessService())->evaluate($contentItemId);
        return $this->ok($result);
    }
}
