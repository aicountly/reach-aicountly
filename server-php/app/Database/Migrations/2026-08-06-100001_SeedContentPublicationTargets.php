<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * reach_content_publication_targets has existed since Phase 2 but nothing —
 * no migration, no seeder — ever put a row in it. The Content Studio schedule
 * screen builds its "Publication Target" dropdown from
 * GET /v1/content/publication-targets, so the select rendered with nothing but
 * the "Select target…" placeholder and the Schedule button stayed permanently
 * disabled: content could never be scheduled through the UI at all.
 *
 * Seed the channels Reach actually publishes to. Idempotent on `name` so a
 * re-run (or a deployment where an operator already created a target by hand)
 * neither duplicates nor overwrites operator edits.
 */
class SeedContentPublicationTargets extends Migration
{
    /** @var list<array{name:string,channel:string,target_url:?string,notes:string}> */
    private const TARGETS = [
        [
            'name'       => 'AICOUNTLY.com Blog',
            'channel'    => 'aicountly_website',
            'target_url' => 'https://aicountly.com/blogs',
            'notes'      => 'Public blog on aicountly.com. Backed by the aicountly_com publication connection.',
        ],
        [
            'name'       => 'AICOUNTLY.com Help Centre',
            'channel'    => 'help_centre',
            'target_url' => 'https://aicountly.com/help',
            'notes'      => 'Knowledge base articles on aicountly.com.',
        ],
        [
            'name'       => 'AICOUNTLY Community',
            'channel'    => 'community_forum',
            'target_url' => 'https://aicountly.com/community',
            'notes'      => 'Community Q&A space.',
        ],
        [
            'name'       => 'LinkedIn',
            'channel'    => 'linkedin',
            'target_url' => null,
            'notes'      => 'Social distribution. Scheduling only — no automated publisher is wired to this channel yet.',
        ],
        [
            'name'       => 'YouTube',
            'channel'    => 'youtube',
            'target_url' => null,
            'notes'      => 'Video publication target used by the video pipeline.',
        ],
        [
            'name'       => 'Email Campaign',
            'channel'    => 'email_campaign',
            'target_url' => null,
            'notes'      => 'Newsletter / lifecycle email sends.',
        ],
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('reach_content_publication_targets')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::TARGETS as $target) {
            $exists = $this->db->table('reach_content_publication_targets')
                ->where('name', $target['name'])
                ->countAllResults();

            if ($exists > 0) {
                continue;
            }

            $this->db->table('reach_content_publication_targets')->insert([
                'name'       => $target['name'],
                'channel'    => $target['channel'],
                'target_url' => $target['target_url'],
                'is_active'  => true,
                'notes'      => $target['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('reach_content_publication_targets')) {
            return;
        }

        $names = array_column(self::TARGETS, 'name');

        // Only remove seeded rows that were never referenced by a schedule.
        $referenced = $this->db->tableExists('reach_content_schedules')
            ? array_column(
                $this->db->table('reach_content_schedules')
                    ->select('publication_target_id')
                    ->distinct()
                    ->get()
                    ->getResultArray(),
                'publication_target_id'
            )
            : [];

        $builder = $this->db->table('reach_content_publication_targets')->whereIn('name', $names);

        if ($referenced !== []) {
            $builder->whereNotIn('id', $referenced);
        }

        $builder->delete();
    }
}
