<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Owner decision (23 Oct 2026): the brand is written "Aicountly" in mixed
 * case, as aicountly.com now writes it. The code defaults and prompts were
 * changed with this migration; this re-cases the rows earlier migrations,
 * the seeder and the taxonomy importer wrote, so new content picks the new
 * casing up.
 *
 * Touched (only where the seeded text is still there — hand edits are left):
 *   - community desk identities: display name, description, topic and the
 *     disclosure strings, and the ai_disclosure column default;
 *   - the seeded static/product/guide/community link titles (published blog
 *     and KB rows are content and are left as published);
 *   - reach_settings rows that still hold the seeded value (brand.name,
 *     blog.default_author, social.default_hashtags,
 *     automation.blog.author_name);
 *   - reach_products names written by the taxonomy importer ("AICountly X").
 *
 * Never touched: published blogs, KB articles, community answers, social
 * posts and videos (they keep the text they were published with).
 *
 * Casing only and idempotent; down() is a no-op because the old casing is
 * not worth restoring and a blind reverse would hit hand-written rows.
 */
class AicountlyBrandCasing extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('reach_community_official_identities', false)) {
            $fields = $this->db->getFieldNames('reach_community_official_identities');
            foreach (['display_name', 'short_description', 'topic_specialisation', 'disclosure_template', 'ai_disclosure'] as $col) {
                if (in_array($col, $fields, true)) {
                    $this->db->query(
                        "UPDATE reach_community_official_identities
                            SET {$col} = REPLACE({$col}, 'AICOUNTLY', 'Aicountly'), updated_at = NOW()
                          WHERE {$col} LIKE '%AICOUNTLY%'"
                    );
                }
            }
            if (in_array('ai_disclosure', $fields, true)) {
                $this->db->query("ALTER TABLE reach_community_official_identities ALTER COLUMN ai_disclosure SET DEFAULT 'AI-assisted Aicountly community contributor'");
            }
        }

        if ($this->db->tableExists('reach_link_registry', false)) {
            $this->db->query(
                "UPDATE reach_link_registry
                    SET title = REPLACE(title, 'AICOUNTLY', 'Aicountly'), updated_at = NOW()
                  WHERE link_type IN ('product', 'guide', 'static', 'community')
                    AND content_item_id IS NULL
                    AND title LIKE '%AICOUNTLY%'"
            );
        }

        if ($this->db->tableExists('reach_settings', false)) {
            $seeded = [
                ['brand.name', '"AICOUNTLY"', '"Aicountly"'],
                ['blog.default_author', '"AICOUNTLY"', '"Aicountly"'],
                ['social.default_hashtags', '["#AICOUNTLY", "#accountingAI"]', '["#Aicountly", "#accountingAI"]'],
                ['automation.blog.author_name', '"AICOUNTLY Editorial Team"', '"Aicountly Editorial Team"'],
            ];
            foreach ($seeded as [$key, $old, $new]) {
                $this->db->query(
                    'UPDATE reach_settings SET value_json = ?::jsonb, updated_at = NOW()
                      WHERE key = ? AND value_json::jsonb = ?::jsonb',
                    [$new, $key, $old]
                );
            }
        }

        if ($this->db->tableExists('reach_products', false)) {
            $this->db->query(
                "UPDATE reach_products
                    SET name = 'Aicountly' || SUBSTRING(name FROM 10), updated_at = NOW()
                  WHERE legacy_code_path = ?
                    AND name LIKE 'AICountly %'",
                ['App\\Libraries\\SaasProductTaxonomy::products()']
            );
        }
    }

    public function down(): void
    {
        // Casing only — see the class comment.
    }
}
