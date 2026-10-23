<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The app a blog post is about (aicountly.com catalogue slug, BlogTaxonomy /
 * ProductCatalog; NULL for a compliance/finance topic article). Sent to
 * aicountly.com as `product_slug` next to the canonical `category`, and the
 * basis of the app-led vs topic share the bot plan steers on.
 *
 * Existing rows stay NULL: `php spark reach:blog-recategorise --apply` fills
 * the ones it can derive with evidence (never guessed).
 */
class AddProductSlugToBlogPublicationProfiles extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE reach_blog_publication_profiles ADD COLUMN IF NOT EXISTS product_slug VARCHAR(64)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_pub_product_slug ON reach_blog_publication_profiles(product_slug)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_blog_pub_category ON reach_blog_publication_profiles(category)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS idx_blog_pub_category');
        $this->db->query('DROP INDEX IF EXISTS idx_blog_pub_product_slug');
        $this->db->query('ALTER TABLE reach_blog_publication_profiles DROP COLUMN IF EXISTS product_slug');
    }
}
