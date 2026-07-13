<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunityOfficialIdentities extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_official_identities (
                id                   BIGSERIAL PRIMARY KEY,
                uuid                 UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                slug                 VARCHAR(120) NOT NULL UNIQUE,
                display_name         VARCHAR(255) NOT NULL,
                department           VARCHAR(120),
                badge_type           VARCHAR(40) NOT NULL DEFAULT 'official'
                                         CHECK (badge_type IN (
                                             'official','compliance','support','product','engineering'
                                         )),
                avatar_reference     VARCHAR(512),
                authorised_scopes    TEXT[] NOT NULL DEFAULT '{}',
                disclosure_template  TEXT,
                approval_requirements JSONB NOT NULL DEFAULT '{}'::jsonb,
                is_active            BOOLEAN NOT NULL DEFAULT TRUE,
                created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoi_slug ON reach_community_official_identities(slug)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcoi_active ON reach_community_official_identities(is_active)');

        $this->db->query("
            INSERT INTO reach_community_official_identities
                (slug, display_name, department, badge_type, disclosure_template)
            VALUES
                ('aicountly-official',    'AICOUNTLY Official',         'General',          'official',    'This is an official response from the AICOUNTLY team.'),
                ('aicountly-support',     'AICOUNTLY Support',          'Customer Support', 'support',     'This response is from the AICOUNTLY Support team.'),
                ('aicountly-product',     'AICOUNTLY Product Team',     'Product',          'product',     'This is an official product guidance from the AICOUNTLY Product Team.'),
                ('aicountly-compliance',  'AICOUNTLY Compliance Team',  'Compliance',       'compliance',  'This response is from the AICOUNTLY Compliance Team and provides general guidance only. Consult a qualified professional for advice specific to your circumstances.'),
                ('aicountly-engineering', 'AICOUNTLY Engineering',      'Engineering',      'engineering', 'This is a technical response from the AICOUNTLY Engineering team.')
            ON CONFLICT (slug) DO NOTHING
        ");
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_official_identities CASCADE');
    }
}
