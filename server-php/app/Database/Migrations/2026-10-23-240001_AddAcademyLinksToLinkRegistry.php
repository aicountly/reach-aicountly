<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Aicountly Academy and its two courses in the internal-link inventory
 * (reach_link_registry): /academy, /edge and /aspire on aicountly.com.
 *
 * Edge and Aspire are Academy courses (training run by Aicountly), not Beta
 * software, so their rows carry category "academy". LinkRegistryService
 * keeps that category out of the evergreen fallback: the course pages are
 * suggested for articles about academy / edge / aspire (product_slug), never
 * as "Related reading" under an unrelated GST or payroll article.
 *
 * Idempotent: re-running re-asserts the same three rows.
 */
class AddAcademyLinksToLinkRegistry extends Migration
{
    private const LINKS = [
        ['/academy', 'Aicountly Academy — industry training for college students', 'academy'],
        ['/edge', 'Aicountly Edge — long-term accounting training programme', 'edge'],
        ['/aspire', 'Aicountly Aspire — short-term courses and workshops', 'aspire'],
    ];

    public function up(): void
    {
        foreach (self::LINKS as [$path, $title, $slug]) {
            $this->db->query(
                "INSERT INTO reach_link_registry (url_path, title, link_type, product_slug, category)
                 VALUES (?, ?, 'product', ?, 'academy')
                 ON CONFLICT (url_path) DO UPDATE SET
                    title        = EXCLUDED.title,
                    link_type    = EXCLUDED.link_type,
                    product_slug = EXCLUDED.product_slug,
                    category     = EXCLUDED.category,
                    status       = 'active',
                    updated_at   = NOW()",
                [$path, $title, $slug]
            );
        }
    }

    public function down(): void
    {
        $this->db->table('reach_link_registry')
            ->whereIn('url_path', array_column(self::LINKS, 0))
            ->delete();
    }
}
