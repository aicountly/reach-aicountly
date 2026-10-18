<?php

declare(strict_types=1);

namespace App\Libraries\Content\Base;

use App\Libraries\AuditLogger;
use App\Libraries\Database\SchemaGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Hands roadmap topics stuck in Reach's retired in-house drafting back to the
 * routines.
 *
 * Until the routines took over, a roadmap CREATE_NEW decision made a
 * GENERATE_BRIEF work block, which created a blog item (idea → brief_ready)
 * and marked the candidate `roadmap_selected` with that item. The outline and
 * draft steps that should have followed are Reach writing article text, which
 * is switched off by design — so those items sit at brief_ready for ever, the
 * candidate is never rescored (the optimiser only loads candidate/scored), and
 * the blog routine is told the key is "already_ingested". That is the August
 * stall in Blog Command Centre → Roadmap → Topic Candidates.
 *
 * This releases such a topic once its item has been idle for IDLE_DAYS: the
 * pre-draft item is archived (audited; it holds no article text), its open
 * work blocks are cancelled, and the candidate goes back to
 * `roadmap_selected` without an item — where GET v1/automation/content-base/next
 * offers it to the blog routine. Items with any draft text, any routine
 * submission or any publication are never touched.
 */
final class RoadmapRelease
{
    public const IDLE_DAYS = 3;

    /** Manual locks older than this are lifted (nothing in Reach sets them any more). */
    public const LOCK_DAYS = 7;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{released:int, unlocked:int, released_candidate_ids:list<int>} */
    public function run(?\DateTimeImmutable $now = null): array
    {
        if (! SchemaGuard::hasTable($this->db, 'reach_topic_candidates')) {
            return ['released' => 0, 'unlocked' => 0, 'released_candidate_ids' => []];
        }

        $now     = $now ?? new \DateTimeImmutable('now');
        $idleCut = $now->modify('-' . self::IDLE_DAYS . ' days')->format('Y-m-d H:i:s');
        $lockCut = $now->modify('-' . self::LOCK_DAYS . ' days')->format('Y-m-d H:i:s');
        $stamp   = $now->format('Y-m-d H:i:s');

        $stuck = $this->db->table('reach_topic_candidates c')
            ->select('c.id AS candidate_id, c.notes, i.id AS item_id, i.workflow_status')
            ->join('reach_content_items i', 'i.id = c.content_item_id')
            ->where('i.content_type', 'blog')
            ->whereIn('i.workflow_status', ContentBaseEntries::LEGACY_PRE_DRAFT)
            ->where('i.published_at IS NULL', null, false)
            ->where('i.updated_at <', $idleCut)
            ->where('i.deleted_at IS NULL', null, false)
            ->where('NOT EXISTS (SELECT 1 FROM reach_routine_submissions s WHERE s.content_item_id = i.id)', null, false)
            ->get()->getResultArray();

        $released = [];
        foreach ($stuck as $row) {
            $itemId = (int) $row['item_id'];
            $this->db->transStart();

            $this->db->table('reach_content_items')->where('id', $itemId)->update([
                'workflow_status' => 'archived',
                'updated_at'      => $stamp,
            ]);
            if (SchemaGuard::hasTable($this->db, 'reach_work_blocks')) {
                $this->db->table('reach_work_blocks')
                    ->where('content_item_id', $itemId)
                    ->whereIn('eligibility_status', ['pending', 'eligible', 'queued', 'blocked', 'failed'])
                    ->update(['eligibility_status' => 'cancelled', 'updated_at' => $stamp]);
            }
            $this->db->table('reach_topic_candidates')->where('id', (int) $row['candidate_id'])->update([
                'content_item_id' => null,
                'status'          => 'roadmap_selected',
                'is_locked'       => false,
                'cooldown_until'  => null,
                'notes'           => trim((string) ($row['notes'] ?? '') . "\n[release] {$stamp}: in-Reach item #{$itemId} ({$row['workflow_status']}) archived; topic handed to the blog routine."),
                'updated_at'      => $stamp,
            ]);

            $this->db->transComplete();
            if ($this->db->transStatus() !== false) {
                $released[] = (int) $row['candidate_id'];
                AuditLogger::record('blog.roadmap_topic_released', [
                    'content_item_id'    => $itemId,
                    'topic_candidate_id' => (int) $row['candidate_id'],
                    'from_status'        => $row['workflow_status'],
                    'reason'             => 'in-Reach drafting is retired; the blog routine writes roadmap topics',
                ]);
            }
        }

        $unlocked = 0;
        foreach ($this->db->table('reach_topic_candidates')->select('id')
            ->where('is_locked', true)->where('updated_at <', $lockCut)->get()->getResultArray() as $r) {
            $this->db->table('reach_topic_candidates')->where('id', (int) $r['id'])->update(['is_locked' => false, 'updated_at' => $stamp]);
            $unlocked++;
        }

        return ['released' => count($released), 'unlocked' => $unlocked, 'released_candidate_ids' => $released];
    }
}
