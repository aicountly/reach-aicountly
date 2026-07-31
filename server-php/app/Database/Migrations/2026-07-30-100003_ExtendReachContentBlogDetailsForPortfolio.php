<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ExtendReachContentBlogDetailsForPortfolio extends Migration
{
    public function up(): void
    {
        $columns = [
            'portfolio_stream'        => "VARCHAR(32) NULL",
            'audience'                => "VARCHAR(100) NULL",
            'industry'                => "VARCHAR(100) NULL",
            'funnel_stage'            => "VARCHAR(32) NULL",
            'search_intent'           => "VARCHAR(64) NULL",
            'campaign'                => "VARCHAR(100) NULL",
            'cta_type'                => "VARCHAR(64) NULL",
            'risk_class'              => "VARCHAR(16) NULL DEFAULT 'LOW'",
            'freshness_sensitivity'   => "VARCHAR(16) NULL",
            'publication_priority'    => "INT NULL DEFAULT 0",
            'review_frequency_days'   => "INT NULL",
            'secondary_product_ids'     => "JSONB NULL",
            'automation_state'        => "VARCHAR(64) NULL",
        ];

        foreach ($columns as $name => $definition) {
            $this->db->query(
                "ALTER TABLE reach_content_blog_details ADD COLUMN IF NOT EXISTS {$name} {$definition}"
            );
        }

        $this->db->query(
            "CREATE INDEX IF NOT EXISTS rcbd_portfolio_stream_idx ON reach_content_blog_details (portfolio_stream)"
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS rcbd_portfolio_stream_idx');
        foreach ([
            'automation_state', 'secondary_product_ids', 'review_frequency_days',
            'publication_priority', 'freshness_sensitivity', 'risk_class', 'cta_type',
            'campaign', 'search_intent', 'funnel_stage', 'industry', 'audience', 'portfolio_stream',
        ] as $col) {
            $this->db->query("ALTER TABLE reach_content_blog_details DROP COLUMN IF EXISTS {$col}");
        }
    }
}
