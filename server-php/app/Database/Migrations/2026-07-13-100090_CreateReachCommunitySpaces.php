<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCommunitySpaces extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS reach_community_spaces (
                id                      BIGSERIAL PRIMARY KEY,
                uuid                    UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                slug                    VARCHAR(120) NOT NULL UNIQUE,
                title                   VARCHAR(255) NOT NULL,
                description             TEXT,
                visibility              VARCHAR(20) NOT NULL DEFAULT 'public'
                                            CHECK (visibility IN ('public','private','restricted')),
                moderation_mode         VARCHAR(20) NOT NULL DEFAULT 'post'
                                            CHECK (moderation_mode IN ('pre','post','none')),
                official_answer_policy  VARCHAR(20) NOT NULL DEFAULT 'optional'
                                            CHECK (official_answer_policy IN ('required','optional','disabled')),
                allowed_content_types   TEXT[] NOT NULL DEFAULT '{}',
                indexing_policy         VARCHAR(20) NOT NULL DEFAULT 'index'
                                            CHECK (indexing_policy IN ('index','noindex')),
                status                  VARCHAR(20) NOT NULL DEFAULT 'active'
                                            CHECK (status IN ('active','archived','disabled')),
                created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcs_slug ON reach_community_spaces(slug)');
        $this->db->query('CREATE INDEX IF NOT EXISTS idx_rcs_status ON reach_community_spaces(status)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS reach_community_spaces CASCADE');
    }
}
