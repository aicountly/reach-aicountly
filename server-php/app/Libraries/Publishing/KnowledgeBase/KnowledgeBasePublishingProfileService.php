<?php

namespace App\Libraries\Publishing\KnowledgeBase;

/**
 * Phase 4 — Knowledge-base publication profile CRUD.
 */
class KnowledgeBasePublishingProfileService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function getOrCreate(int $contentItemId): array
    {
        $existing = $this->db->table('reach_kb_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if ($existing) {
            return $existing;
        }

        $this->db->table('reach_kb_publication_profiles')->insert([
            'content_item_id' => $contentItemId,
            'article_type'    => 'concept',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return $this->db->table('reach_kb_publication_profiles')
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();
    }

    public function update(int $contentItemId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        // JSON-encode arrays
        foreach (['applicable_versions_json','prerequisites_json','steps_json','troubleshooting_json','related_articles_json','support_escalation_json'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }

        $exists = $this->db->table('reach_kb_publication_profiles')
            ->where('content_item_id', $contentItemId)->countAllResults();

        if ($exists) {
            $this->db->table('reach_kb_publication_profiles')
                ->where('content_item_id', $contentItemId)->update($data);
        } else {
            $data['content_item_id'] = $contentItemId;
            $data['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('reach_kb_publication_profiles')->insert($data);
        }

        return true;
    }
}
