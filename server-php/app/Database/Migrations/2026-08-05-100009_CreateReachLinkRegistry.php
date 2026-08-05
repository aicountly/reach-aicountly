<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Canonical inventory of linkable aicountly.com URLs (product pages, guides,
 * published blogs, KB articles) powering internal-link automation across all
 * generated content. Seeded with the static product/guide pages; publish
 * events keep blog/KB rows current.
 */
class CreateReachLinkRegistry extends Migration
{
    private const PRODUCTS = [
        ['smart-books', 'Smart Books — AI accounting software', '/smart-books'],
        ['financial-reporting', 'Financial Reporting — Schedule III statements', '/financial-reporting'],
        ['auditor', 'Auditor — audit engagements and working papers', '/auditor'],
        ['secretarial', 'Secretarial — MCA compliance management', '/secretarial'],
        ['hrms', 'HRMS — payroll and statutory compliance', '/hrms'],
        ['our-people', 'Our People — employee self-service', '/our-people'],
        ['contacts', 'Contacts — business contact management', '/contacts'],
        ['calendar', 'Calendar — compliance calendar', '/calendar'],
        ['chat', 'Chat — team messaging', '/chat'],
        ['docs', 'Docs — document collaboration', '/docs'],
        ['vault', 'Vault — secure file storage', '/vault'],
        ['manage', 'Manage — company, branch and FY context', '/manage'],
        ['buddy', 'AI Pulse — AI business insights', '/buddy'],
    ];

    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_link_registry (
                id               BIGSERIAL PRIMARY KEY,
                url_path         VARCHAR(500) NOT NULL UNIQUE,
                title            VARCHAR(300) NOT NULL,
                link_type        VARCHAR(24) NOT NULL DEFAULT 'product'
                                 CHECK (link_type IN ('product','guide','blog','knowledge_base','community','static')),
                product_slug     VARCHAR(100),
                category         VARCHAR(100),
                content_item_id  BIGINT,
                status           VARCHAR(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active','retired')),
                last_verified_at TIMESTAMPTZ,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_link_registry_type ON reach_link_registry(link_type, status)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_link_registry_product ON reach_link_registry(product_slug)');

        foreach (self::PRODUCTS as [$slug, $title, $path]) {
            $this->db->query(
                "INSERT INTO reach_link_registry (url_path, title, link_type, product_slug)
                 VALUES (?, ?, 'product', ?) ON CONFLICT (url_path) DO NOTHING",
                [$path, $title, $slug]
            );
        }
        $this->db->query(
            "INSERT INTO reach_link_registry (url_path, title, link_type)
             VALUES ('/guides', 'Product workflow guides', 'guide'),
                    ('/help', 'Help Centre and knowledge base', 'static'),
                    ('/community', 'AICOUNTLY community Q&A', 'community'),
                    ('/pricing', 'Plans and pricing', 'static')
             ON CONFLICT (url_path) DO NOTHING"
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_link_registry', true);
    }
}
