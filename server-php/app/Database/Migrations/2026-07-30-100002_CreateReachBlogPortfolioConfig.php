<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateReachBlogPortfolioConfig extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                          => ['type' => 'BIGSERIAL'],
            'marketing_percent'           => ['type' => 'INT', 'null' => false, 'default' => 45],
            'product_percent'             => ['type' => 'INT', 'null' => false, 'default' => 35],
            'problem_to_product_percent'  => ['type' => 'INT', 'null' => false, 'default' => 20],
            'settings_json'               => ['type' => 'JSONB', 'null' => true],
            'updated_by'                  => ['type' => 'BIGINT', 'null' => true],
            'created_at'                  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
            'updated_at'                  => ['type' => 'TIMESTAMPTZ', 'null' => false, 'default' => new RawSql('NOW()')],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('reach_blog_portfolio_config', true);

        $this->db->table('reach_blog_portfolio_config')->insert([
            'marketing_percent'          => 45,
            'product_percent'            => 35,
            'problem_to_product_percent' => 20,
            'settings_json'              => json_encode([
                'automation_window' => [
                    'timezone' => 'Asia/Kolkata',
                    'windows'  => [
                        ['start' => '00:00', 'end' => '08:59'],
                        ['start' => '19:00', 'end' => '23:59'],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('reach_blog_portfolio_config', true);
    }
}
