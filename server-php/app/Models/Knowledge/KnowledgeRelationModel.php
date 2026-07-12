<?php

namespace App\Models\Knowledge;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Generic helper for inserting / deleting junction-table rows.
 * Each method validates FK column names against an allowlist before
 * constructing queries to prevent SQL injection via column names.
 */
class KnowledgeRelationModel
{
    private static array $allowedTables = [
        'reach_product_personas'     => ['product_id', 'persona_id'],
        'reach_product_industries'   => ['product_id', 'industry_id'],
        'reach_product_markets'      => ['product_id', 'market_id'],
        'reach_module_personas'      => ['module_id', 'persona_id'],
        'reach_feature_personas'     => ['feature_id', 'persona_id'],
        'reach_feature_industries'   => ['feature_id', 'industry_id'],
        'reach_feature_problems'     => ['feature_id', 'problem_id'],
        'reach_intent_products'      => ['intent_id', 'product_id'],
        'reach_intent_modules'       => ['intent_id', 'module_id'],
        'reach_intent_features'      => ['intent_id', 'feature_id'],
        'reach_intent_personas'      => ['intent_id', 'persona_id'],
        'reach_intent_topic_clusters'=> ['intent_id', 'cluster_id'],
        'reach_product_evidence'     => ['product_id', 'evidence_id'],
        'reach_feature_evidence'     => ['feature_id', 'evidence_id'],
        'reach_claim_evidence'       => ['claim_id', 'evidence_id'],
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Attach a related entity to a parent entity via a junction table.
     * Silently ignores duplicates (unique constraint violation).
     */
    public function attach(string $table, array $data, ?int $createdBy = null): bool
    {
        $this->assertTableAndColumns($table, $data);
        $existing = $this->db->table($table)
            ->where($data)
            ->countAllResults();
        if ($existing > 0) {
            return true;
        }
        $insert = $data;
        $insert['created_by'] = $createdBy;
        $insert['created_at'] = date('Y-m-d H:i:s');
        return $this->db->table($table)->insert($insert);
    }

    /**
     * Detach a related entity from a parent entity.
     */
    public function detach(string $table, array $data): bool
    {
        $this->assertTableAndColumns($table, $data);
        return $this->db->table($table)->where($data)->delete();
    }

    /**
     * Return all related IDs for a given parent FK value.
     */
    public function listRelated(string $table, string $parentCol, int $parentId, string $relatedCol): array
    {
        $this->assertTableCol($table, $parentCol);
        $this->assertTableCol($table, $relatedCol);
        $rows = $this->db->table($table)
            ->select($relatedCol)
            ->where($parentCol, $parentId)
            ->get()->getResultArray();
        return array_column($rows, $relatedCol);
    }

    /**
     * Sync a set of related IDs for a parent, removing stale and adding new.
     */
    public function sync(string $table, string $parentCol, int $parentId, string $relatedCol, array $ids, ?int $createdBy = null): void
    {
        $this->assertTableCol($table, $parentCol);
        $this->assertTableCol($table, $relatedCol);

        $existing = $this->listRelated($table, $parentCol, $parentId, $relatedCol);
        $toAdd    = array_diff($ids, $existing);
        $toRemove = array_diff($existing, $ids);

        foreach ($toAdd as $id) {
            $this->attach($table, [$parentCol => $parentId, $relatedCol => (int) $id], $createdBy);
        }
        foreach ($toRemove as $id) {
            $this->detach($table, [$parentCol => $parentId, $relatedCol => (int) $id]);
        }
    }

    private function assertTableAndColumns(string $table, array $data): void
    {
        if (! isset(self::$allowedTables[$table])) {
            throw new \InvalidArgumentException("Unknown relation table: $table");
        }
        foreach (array_keys($data) as $col) {
            $this->assertTableCol($table, $col);
        }
    }

    private function assertTableCol(string $table, string $col): void
    {
        if (! isset(self::$allowedTables[$table])) {
            throw new \InvalidArgumentException("Unknown relation table: $table");
        }
        if (! in_array($col, self::$allowedTables[$table], true)) {
            throw new \InvalidArgumentException("Column $col not allowed in $table");
        }
    }
}
