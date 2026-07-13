<?php

namespace App\Libraries\Publishing\Blog;

/**
 * Phase 4 — Blog publication profile CRUD.
 */
class BlogPublishingProfileService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function getOrCreate(int $contentItemId): array
    {
        $existing = $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if ($existing) {
            return $existing;
        }

        $this->db->table('reach_blog_publication_profiles')->insert([
            'content_item_id' => $contentItemId,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();
    }

    public function update(int $contentItemId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $exists = $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->countAllResults();

        if ($exists) {
            $this->db->table('reach_blog_publication_profiles')
                ->where('content_item_id', $contentItemId)
                ->update($data);
        } else {
            $data['content_item_id'] = $contentItemId;
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('reach_blog_publication_profiles')->insert($data);
        }

        return true;
    }

    public function setRefreshStatus(int $contentItemId, string $status): void
    {
        $allowed = ['published','review_due','refresh_due','refresh_in_progress','reapproval_required','republish_ready','superseded','archived'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException("Invalid refresh status: {$status}");
        }

        $this->db->table('reach_blog_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->update(['refresh_status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
