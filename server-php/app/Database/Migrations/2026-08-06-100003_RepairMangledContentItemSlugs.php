<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ContentItemModel::buildUniqueSlug ran its [^a-z0-9] filter before
 * strtolower(), so every capital letter was treated as a separator and eaten:
 * "TDS Compliance Basics for Growing Companies" was stored as
 * "ompliance-asics-for-rowing-ompanies".
 *
 * The generator is fixed; this repairs the rows it already mangled.
 *
 * Deliberately conservative — a slug is a public URL, so a row is only
 * rewritten when it has never gone live (no publication deployment ever
 * received a public_content_id for it). Anything already published keeps its
 * URL and must be redirected by hand if it needs changing.
 */
class RepairMangledContentItemSlugs extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('reach_content_items')) {
            return;
        }

        $live = [];
        if ($this->db->tableExists('reach_publication_deployments')) {
            $live = array_column(
                $this->db->table('reach_publication_deployments')
                    ->select('content_item_id')
                    ->where('public_content_id IS NOT NULL', null, false)
                    ->distinct()
                    ->get()
                    ->getResultArray(),
                'content_item_id'
            );
            $live = array_map('intval', $live);
        }

        $rows = $this->db->table('reach_content_items')
            ->select('id, title, slug')
            ->get()
            ->getResultArray();

        // Slugs already in use, so repairs cannot collide with each other or
        // with a live row that is deliberately being left alone.
        $taken = [];
        foreach ($rows as $row) {
            $taken[strtolower((string) $row['slug'])] = true;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (in_array($id, $live, true)) {
                continue;
            }

            $title   = trim((string) $row['title']);
            $current = strtolower((string) $row['slug']);
            if ($title === '' || $current === '') {
                continue;
            }

            // Only touch rows whose slug is exactly what the buggy generator
            // would have produced for this title. A slug an editor chose by
            // hand will not match, and is left alone.
            $mangled = trim(strtolower((string) preg_replace('/[^a-z0-9]+/', '-', $title)), '-');
            if ($mangled === '' || ! preg_match('/^' . preg_quote($mangled, '/') . '(-\d+)?$/', $current)) {
                continue;
            }

            $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
            if ($base === '' || $base === $current) {
                continue;
            }

            $candidate = $base;
            $n         = 2;
            while (isset($taken[$candidate])) {
                $candidate = $base . '-' . $n++;
            }

            unset($taken[strtolower((string) $row['slug'])]);
            $taken[$candidate] = true;

            $this->db->table('reach_content_items')->where('id', $id)->update([
                'slug'       => $candidate,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Slugs are not restorable — the original values were lossy. No-op.
    }
}
