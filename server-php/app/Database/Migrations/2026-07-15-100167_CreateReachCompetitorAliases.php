<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachCompetitorAliases extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_competitor_aliases (
                id              BIGSERIAL PRIMARY KEY,
                competitor_id   BIGINT NOT NULL REFERENCES reach_competitors(id) ON DELETE CASCADE,
                alias_type      VARCHAR(20) NOT NULL CHECK (alias_type IN ('product','brand','domain','acronym')),
                alias_value     VARCHAR(200) NOT NULL,
                is_canonical    BOOLEAN NOT NULL DEFAULT FALSE,
                added_by        BIGINT,
                added_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_competitor_alias UNIQUE (competitor_id, alias_type, alias_value)
            )
        ");
        $this->db->query("CREATE INDEX idx_competitor_aliases_competitor ON reach_competitor_aliases (competitor_id)");
        $this->db->query("CREATE INDEX idx_competitor_aliases_value ON reach_competitor_aliases (alias_value)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_competitor_aliases CASCADE");
    }
}
