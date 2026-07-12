<?php

namespace App\Models\Content;

/**
 * Helper class for managing content ↔ knowledge entity junction records.
 *
 * Not a CI4 Model — wraps raw DB calls for all 14 junction tables.
 * Mirrors the approach used by KnowledgeRelationModel in Phase 1.
 */
class ContentKnowledgeMapModel
{
    private const TABLE_MAP = [
        'product'        => ['table' => 'reach_content_product_map',       'fk' => 'product_id'],
        'module'         => ['table' => 'reach_content_module_map',        'fk' => 'module_id'],
        'feature'        => ['table' => 'reach_content_feature_map',       'fk' => 'feature_id'],
        'persona'        => ['table' => 'reach_content_persona_map',       'fk' => 'persona_id'],
        'industry'       => ['table' => 'reach_content_industry_map',      'fk' => 'industry_id'],
        'market'         => ['table' => 'reach_content_market_map',        'fk' => 'market_id'],
        'problem'        => ['table' => 'reach_content_problem_map',       'fk' => 'problem_id'],
        'search_intent'  => ['table' => 'reach_content_search_intent_map', 'fk' => 'search_intent_id'],
        'topic'          => ['table' => 'reach_content_topic_map',         'fk' => 'topic_cluster_id'],
        'claim'          => ['table' => 'reach_content_claim_map',         'fk' => 'claim_id'],
        'evidence'       => ['table' => 'reach_content_evidence_map',      'fk' => 'evidence_id'],
        'source'         => ['table' => 'reach_content_source_map',        'fk' => 'source_id'],
        'citation'       => ['table' => 'reach_content_citation_map',      'fk' => 'citation_id'],
        'brand_rule'     => ['table' => 'reach_content_brand_rule_map',    'fk' => 'brand_rule_id'],
    ];

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function getMappings(int $contentItemId, string $entityType): array
    {
        $meta = self::TABLE_MAP[$entityType] ?? null;
        if ($meta === null) {
            return [];
        }
        return $this->db->table($meta['table'])
            ->where('content_item_id', $contentItemId)
            ->get()
            ->getResultArray();
    }

    public function addMapping(int $contentItemId, string $entityType, int $entityId, ?int $createdBy = null): bool
    {
        $meta = self::TABLE_MAP[$entityType] ?? null;
        if ($meta === null) {
            return false;
        }
        $existing = $this->db->table($meta['table'])
            ->where('content_item_id', $contentItemId)
            ->where($meta['fk'], $entityId)
            ->get()
            ->getFirstRow();

        if ($existing) {
            return true;
        }

        $this->db->table($meta['table'])->insert([
            'content_item_id' => $contentItemId,
            $meta['fk']       => $entityId,
            'created_by'      => $createdBy,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        return $this->db->affectedRows() > 0;
    }

    public function removeMapping(int $contentItemId, string $entityType, int $entityId): bool
    {
        $meta = self::TABLE_MAP[$entityType] ?? null;
        if ($meta === null) {
            return false;
        }
        $this->db->table($meta['table'])
            ->where('content_item_id', $contentItemId)
            ->where($meta['fk'], $entityId)
            ->delete();
        return $this->db->affectedRows() > 0;
    }

    public function syncMappings(int $contentItemId, string $entityType, array $entityIds, ?int $createdBy = null): void
    {
        $meta = self::TABLE_MAP[$entityType] ?? null;
        if ($meta === null) {
            return;
        }

        $this->db->table($meta['table'])
            ->where('content_item_id', $contentItemId)
            ->delete();

        foreach (array_unique($entityIds) as $entityId) {
            $this->db->table($meta['table'])->insert([
                'content_item_id' => $contentItemId,
                $meta['fk']       => (int) $entityId,
                'created_by'      => $createdBy,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function allForItem(int $contentItemId): array
    {
        $result = [];
        foreach (self::TABLE_MAP as $type => $meta) {
            $rows = $this->db->table($meta['table'])
                ->where('content_item_id', $contentItemId)
                ->get()
                ->getResultArray();
            $result[$type] = array_column($rows, $meta['fk']);
        }
        return $result;
    }

    public static function supportedTypes(): array
    {
        return array_keys(self::TABLE_MAP);
    }
}
