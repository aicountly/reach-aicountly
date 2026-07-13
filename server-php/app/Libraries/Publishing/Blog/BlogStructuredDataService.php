<?php

namespace App\Libraries\Publishing\Blog;

use App\Libraries\Publishing\Seo\StructuredDataValidator;

/**
 * Phase 4 — Generate and store BlogPosting/Article JSON-LD for blog content.
 *
 * Only approved schemas may be generated. Fake ratings, reviews, or prices
 * are strictly prohibited.
 */
class BlogStructuredDataService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private StructuredDataValidator $validator;

    public function __construct()
    {
        $this->db      = \Config\Database::connect();
        $this->validator = new StructuredDataValidator();
    }

    /**
     * Build a BlogPosting schema from approved content and profile.
     *
     * @param array $item    reach_content_items row
     * @param array $profile reach_blog_publication_profiles row
     * @param array $seo     reach_content_seo_profiles row
     * @param string $canonicalUrl Resolved canonical URL
     */
    public function buildBlogPosting(array $item, array $profile, array $seo, string $canonicalUrl): array
    {
        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'BlogPosting',
            'headline'         => $item['title'] ?? '',
            'description'      => $seo['meta_description'] ?? '',
            'url'              => $canonicalUrl,
            'datePublished'    => $item['created_at'] ?? '',
            'dateModified'     => $item['updated_at'] ?? '',
            'inLanguage'       => $item['language'] ?? 'en',
            'publisher'        => [
                '@type' => 'Organization',
                'name'  => 'AICOUNTLY',
                'url'   => 'https://aicountly.com',
            ],
        ];

        if (!empty($profile['author_reference'])) {
            $schema['author'] = ['@type' => 'Person', 'name' => $profile['author_reference']];
        }

        if (!empty($profile['featured_image_reference'])) {
            $schema['image'] = $profile['featured_image_reference'];
        }

        if (!empty($profile['category'])) {
            $schema['articleSection'] = $profile['category'];
        }

        return $schema;
    }

    /**
     * Persist structured data for a content item, replacing previous for same schema type.
     */
    public function store(int $contentItemId, ?int $versionId, array $schema): void
    {
        $schemaType = $schema['@type'] ?? '';
        $validation = $this->validator->validate($schema);

        $this->db->table('reach_content_structured_data')
            ->where('content_item_id', $contentItemId)
            ->where('schema_type', $schemaType)
            ->delete();

        $this->db->table('reach_content_structured_data')->insert([
            'content_item_id'       => $contentItemId,
            'content_version_id'    => $versionId,
            'schema_type'           => $schemaType,
            'schema_json'           => json_encode($schema),
            'validation_status'     => $validation['valid'] ? 'valid' : 'invalid',
            'validation_errors_json'=> json_encode($validation['errors'] ?? []),
            'is_primary'            => true,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ]);
    }
}
