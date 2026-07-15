<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReachSitemapSnapshots extends Migration
{
    public function up(): void
    {
        $this->db->query("
            CREATE TABLE reach_sitemap_snapshots (
                id                  BIGSERIAL PRIMARY KEY,
                uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id           BIGINT NOT NULL,
                generated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                total_entries       INT NOT NULL DEFAULT 0,
                included_entries    INT NOT NULL DEFAULT 0,
                excluded_noindex    INT NOT NULL DEFAULT 0,
                excluded_withdrawn  INT NOT NULL DEFAULT 0,
                excluded_other      INT NOT NULL DEFAULT 0,
                status              VARCHAR(20) NOT NULL DEFAULT 'pending'
                                    CHECK (status IN ('pending','generated','validated','failed')),
                generation_secs     NUMERIC(8,3),
                error_message       TEXT,
                triggered_by        VARCHAR(20) NOT NULL DEFAULT 'job'
                                    CHECK (triggered_by IN ('job','manual','api')),
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_sitemap_snapshot_uuid UNIQUE (uuid)
            )
        ");
        $this->db->query("CREATE INDEX idx_sitemap_snapshots_tenant ON reach_sitemap_snapshots (tenant_id, generated_at DESC)");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS reach_sitemap_snapshots CASCADE");
    }
}
