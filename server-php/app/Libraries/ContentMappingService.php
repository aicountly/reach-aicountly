<?php

namespace App\Libraries;

use App\Models\Content\ContentKnowledgeMapModel;

/**
 * Synchronises Phase 1 knowledge entity links for a content item.
 *
 * Validates that referenced entities exist (and optionally are approved) before
 * writing junction records. Does not create the knowledge entities themselves.
 */
class ContentMappingService
{
    private ContentKnowledgeMapModel $maps;
    private AuditLogger             $audit;

    /** Tables + FK pairs for existence validation */
    private const ENTITY_TABLES = [
        'product'       => 'reach_products',
        'module'        => 'reach_modules',
        'feature'       => 'reach_features',
        'persona'       => 'reach_personas',
        'industry'      => 'reach_industries',
        'market'        => 'reach_markets',
        'problem'       => 'reach_business_problems',
        'search_intent' => 'reach_search_intents',
        'topic'         => 'reach_topic_clusters',
        'claim'         => 'reach_product_claims',
        'evidence'      => 'reach_evidence',
        'source'        => 'reach_sources',
        'citation'      => 'reach_citations',
        'brand_rule'    => 'reach_brand_rules',
    ];

    public function __construct()
    {
        $this->maps  = new ContentKnowledgeMapModel();
        $this->audit = new AuditLogger();
    }

    /**
     * Sync all knowledge mappings for a content item.
     *
     * @param array $mappings ['product' => [1,2], 'persona' => [3], ...]
     */
    public function sync(int $contentItemId, array $mappings, array $actor = []): void
    {
        $db = \Config\Database::connect();

        foreach ($mappings as $entityType => $ids) {
            $table = self::ENTITY_TABLES[$entityType] ?? null;
            if ($table === null) {
                continue;
            }

            $ids = array_filter(array_map('intval', (array) $ids));

            // Validate referenced IDs exist
            foreach ($ids as $entityId) {
                $exists = $db->table($table)->where('id', $entityId)->where('deleted_at IS NULL')->countAllResults();
                if (!$exists) {
                    throw new \RuntimeException("Referenced {$entityType} ID {$entityId} does not exist.");
                }
            }

            $this->maps->syncMappings($contentItemId, $entityType, $ids, $actor['id'] ?? null);
        }

        $this->audit->log($actor['id'] ?? null, AuditLogger::CONTENT_MAPPED, 'content', $contentItemId, null, null, [
            'entity_types' => array_keys($mappings),
        ]);
    }

    public function getMappings(int $contentItemId): array
    {
        return $this->maps->allForItem($contentItemId);
    }

    public function addMapping(int $contentItemId, string $entityType, int $entityId, array $actor = []): bool
    {
        $table = self::ENTITY_TABLES[$entityType] ?? null;
        if ($table === null) {
            throw new \RuntimeException("Unknown entity type: {$entityType}");
        }

        $db     = \Config\Database::connect();
        $exists = $db->table($table)->where('id', $entityId)->where('deleted_at IS NULL')->countAllResults();
        if (!$exists) {
            throw new \RuntimeException("Referenced {$entityType} ID {$entityId} does not exist.");
        }

        return $this->maps->addMapping($contentItemId, $entityType, $entityId, $actor['id'] ?? null);
    }

    public function removeMapping(int $contentItemId, string $entityType, int $entityId): bool
    {
        return $this->maps->removeMapping($contentItemId, $entityType, $entityId);
    }
}
