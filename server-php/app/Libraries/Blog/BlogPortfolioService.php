<?php

namespace App\Libraries\Blog;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Portfolio mix configuration (marketing / product / problem_to_product).
 */
class BlogPortfolioService
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * @return array<string,mixed>
     */
    public function get(): array
    {
        $row = $this->db->table('reach_blog_portfolio_config')
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        if (! $row) {
            return [
                'marketing_percent'          => 45,
                'product_percent'            => 35,
                'problem_to_product_percent' => 20,
                'settings_json'              => [],
            ];
        }

        if (isset($row['settings_json']) && is_string($row['settings_json'])) {
            $decoded = json_decode($row['settings_json'], true);
            $row['settings_json'] = is_array($decoded) ? $decoded : [];
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function update(array $data, ?int $updatedBy = null): array
    {
        $marketing = (int) ($data['marketing_percent'] ?? 45);
        $product   = (int) ($data['product_percent'] ?? 35);
        $problem   = (int) ($data['problem_to_product_percent'] ?? 20);

        if ($marketing + $product + $problem !== 100) {
            throw new \InvalidArgumentException('Portfolio percents must sum to 100.');
        }

        $payload = [
            'marketing_percent'          => $marketing,
            'product_percent'            => $product,
            'problem_to_product_percent' => $problem,
            'updated_by'                 => $updatedBy,
            'updated_at'                 => date('Y-m-d H:i:s'),
        ];

        if (array_key_exists('settings_json', $data)) {
            $payload['settings_json'] = is_string($data['settings_json'])
                ? $data['settings_json']
                : json_encode($data['settings_json'], JSON_UNESCAPED_SLASHES);
        }

        $existing = $this->db->table('reach_blog_portfolio_config')->orderBy('id', 'ASC')->get()->getRowArray();
        if ($existing) {
            $this->db->table('reach_blog_portfolio_config')->where('id', $existing['id'])->update($payload);
        } else {
            $payload['created_at'] = date('Y-m-d H:i:s');
            $this->db->table('reach_blog_portfolio_config')->insert($payload);
        }

        return $this->get();
    }
}
