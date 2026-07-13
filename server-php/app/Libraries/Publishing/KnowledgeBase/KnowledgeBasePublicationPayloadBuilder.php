<?php

namespace App\Libraries\Publishing\KnowledgeBase;

use App\Libraries\HtmlSanitizer;

/**
 * Phase 4 — Assembles the safe, approved publication payload for KB content.
 */
class KnowledgeBasePublicationPayloadBuilder
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * @throws \RuntimeException if content is not approved or missing
     */
    public function build(int $contentItemId, int $contentVersionId): array
    {
        $item = $this->db->table('reach_content_items')
            ->where('id', $contentItemId)->get()->getRowArray();

        if (!$item || $item['approval_status'] !== 'approved') {
            throw new \RuntimeException('Content must be human-approved before payload can be built');
        }

        $version = $this->db->table('reach_content_versions')
            ->where('id', $contentVersionId)
            ->where('content_item_id', $contentItemId)
            ->get()->getRowArray();

        if (!$version) {
            throw new \RuntimeException('Content version not found');
        }

        $profile = $this->db->table('reach_kb_publication_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];

        $seo = $this->db->table('reach_content_seo_profiles')
            ->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];

        $kbDetails = $this->db->table('reach_content_knowledge_base_details')
            ->where('content_item_id', $contentItemId)->get()->getRowArray() ?? [];

        $snapshot = is_string($version['snapshot_json'])
            ? json_decode($version['snapshot_json'], true)
            : ($version['snapshot_json'] ?? []);

        $rawBody  = $snapshot['body_html'] ?? '';
        $safeBody = HtmlSanitizer::sanitize($rawBody);

        // Structured data
        $structuredData = $this->db->table('reach_content_structured_data')
            ->where('content_item_id', $contentItemId)
            ->whereIn('validation_status', ['valid', 'pending'])
            ->get()->getResultArray();

        $sdArray = array_map(
            fn($row) => json_decode($row['schema_json'], true),
            $structuredData
        );

        // Product/module/feature names
        $productName = null;
        if (!empty($profile['product_id'])) {
            $product = $this->db->table('reach_products')
                ->where('id', $profile['product_id'])->get()->getRowArray();
            $productName = $product['name'] ?? null;
        }

        $moduleName = null;
        if (!empty($profile['module_id'])) {
            $module = $this->db->table('reach_modules')
                ->where('id', $profile['module_id'])->get()->getRowArray();
            $moduleName = $module['name'] ?? null;
        }

        $featureName = null;
        if (!empty($profile['feature_id'])) {
            $feature = $this->db->table('reach_features')
                ->where('id', $profile['feature_id'])->get()->getRowArray();
            $featureName = $feature['name'] ?? null;
        }

        $decode = fn($v) => is_string($v) ? (json_decode($v, true) ?? []) : ($v ?? []);

        $payload = [
            'title'                       => $item['title'] ?? '',
            'slug'                        => $seo['slug'] ?? $item['slug'] ?? '',
            'article_type'                => $profile['article_type'] ?? 'concept',
            'summary'                     => $snapshot['summary'] ?? '',
            'body_html'                   => $safeBody,
            'product'                     => $productName,
            'module'                      => $moduleName,
            'feature'                     => $featureName,
            'applicable_versions'         => $decode($profile['applicable_versions_json'] ?? null),
            'availability_status'         => 'available',
            'difficulty_level'            => $profile['difficulty_level'] ?? null,
            'estimated_completion_minutes'=> $profile['estimated_completion_minutes'] ?? null,
            'prerequisites'               => $decode($profile['prerequisites_json'] ?? null),
            'steps'                       => $decode($profile['steps_json'] ?? null),
            'troubleshooting'             => $decode($profile['troubleshooting_json'] ?? null),
            'related_articles'            => $decode($profile['related_articles_json'] ?? null),
            'support_escalation'          => $decode($profile['support_escalation_json'] ?? null),
            'meta_title'                  => $seo['meta_title'] ?? $item['title'] ?? '',
            'meta_description'            => $seo['meta_description'] ?? '',
            'canonical_preference'        => $seo['canonical_preference'] ?? 'self_canonical',
            'robots_directive'            => $seo['robots_directive'] ?? 'index,follow',
            'structured_data'             => $sdArray,
            'feedback_enabled'            => (bool) ($profile['feedback_enabled'] ?? true),
            'language'                    => $item['language'] ?? 'en',
            'scheduled_at'               => null,
        ];

        return $payload;
    }

    public function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
