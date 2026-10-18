<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * reach_routine_submissions: one row per routine draft and where it is in the
 * vet → approve → cover → publish → verify pipeline. Also owns the daily
 * quota, counted per IST calendar day.
 */
class RoutineSubmissions
{
    public const PENDING_VETTING = 'pending_vetting';
    public const REJECTED        = 'rejected';
    public const HELD_HIGH_RISK  = 'held_high_risk';
    public const AWAITING_COVER  = 'awaiting_cover';
    public const APPROVED        = 'approved';
    public const PUBLISH_BLOCKED = 'publish_blocked';
    public const PUBLISHING      = 'publishing';
    public const LIVE            = 'live';
    public const PUBLISH_FAILED  = 'publish_failed';

    /** Statuses the sweep keeps working on. */
    public const OPEN = [self::PENDING_VETTING, self::AWAITING_COVER, self::APPROVED, self::PUBLISH_BLOCKED, self::PUBLISHING];

    private const JSON_FIELDS = ['reasons_json', 'scores_json', 'vetting_run_ids_json', 'submission_json'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Refuse a new submission when the type is switched off or today's quota
     * is used. Returns the quota so the response can tell the routine how many
     * it may still send.
     *
     * @return array{daily_limit: int, used_today: int, remaining_today: int}
     * @throws RoutineIntakeRefused
     */
    public function assertAccepting(string $contentType): array
    {
        $label = $contentType === 'blog' ? 'Blog' : 'Knowledge Base';
        if (! RoutineFlags::enabled($contentType . '.enabled')) {
            throw new RoutineIntakeRefused("{$label} automation is switched off in Settings → AI & Automation. Stop this run.", 403);
        }

        $quota = $this->quota($contentType);
        if ($quota['remaining_today'] <= 0) {
            throw new RoutineIntakeRefused(
                "Today's {$label} quota is used ({$quota['used_today']}/{$quota['daily_limit']} per IST day). Stop this run and do not retry until tomorrow.",
                429,
                $quota,
            );
        }

        return $quota;
    }

    /** @return array{daily_limit: int, used_today: int, remaining_today: int} */
    public function quota(string $contentType): array
    {
        $limit = max(0, (int) ReachConfig::get("automation.{$contentType}.daily_limit", 0));
        $used  = $this->usedToday($contentType);

        return ['daily_limit' => $limit, 'used_today' => $used, 'remaining_today' => max(0, $limit - $used)];
    }

    /**
     * Submissions accepted today (IST). Rejected drafts do not count — the
     * quota is a publishing volume, and a rejection publishes nothing.
     */
    public function usedToday(string $contentType): int
    {
        // Older approved items adopted after re-vetting (origin 'revet')
        // publish nothing new and do not use up the routine's quota.
        return $this->db->table('reach_routine_submissions')
            ->where('content_type', $contentType)
            ->where('status !=', self::REJECTED)
            ->where('origin !=', 'revet')
            ->where('created_at >=', self::istMidnight())
            ->countAllResults();
    }

    /** Today's IST midnight, expressed in the application's timezone. */
    public static function istMidnight(): string
    {
        $ist = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Kolkata'));

        return $ist->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $submission */
    public function create(string $contentType, int $contentItemId, ?string $contentKey, array $submission): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('reach_routine_submissions')->insert([
            'content_type'    => $contentType,
            'content_item_id' => $contentItemId,
            'content_key'     => $contentKey !== '' ? $contentKey : null,
            'status'          => self::PENDING_VETTING,
            'submission_json' => json_encode($submission, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table('reach_routine_submissions')->where('id', $id)->get()->getRowArray();

        return $row ? $this->decode($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByItem(int $contentItemId): ?array
    {
        $row = $this->db->table('reach_routine_submissions')->where('content_item_id', $contentItemId)->get()->getRowArray();

        return $row ? $this->decode($row) : null;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        foreach (self::JSON_FIELDS as $field) {
            if (array_key_exists($field, $data) && ! is_string($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('reach_routine_submissions')->where('id', $id)->update($data);
    }

    /**
     * Take the row for one pipeline pass. A pass that dies mid-way (a killed
     * web request, a worker crash) simply lets the lease lapse, and the sweep
     * picks the row up again.
     */
    public function claim(int $id, int $minutes = 15): bool
    {
        $now   = date('Y-m-d H:i:s');
        $until = date('Y-m-d H:i:s', time() + $minutes * 60);
        $this->db->query(
            'UPDATE reach_routine_submissions SET locked_until = ?, updated_at = ?
             WHERE id = ? AND (locked_until IS NULL OR locked_until < ?)',
            [$until, $now, $id, $now]
        );

        return $this->db->affectedRows() === 1;
    }

    public function release(int $id): void
    {
        $this->db->table('reach_routine_submissions')->where('id', $id)->update(['locked_until' => null]);
    }

    /**
     * Rows with work due now, oldest first.
     *
     * @return list<int>
     */
    public function dueIds(int $limit = 25): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = $this->db->query(
            "SELECT id FROM reach_routine_submissions
             WHERE (locked_until IS NULL OR locked_until < ?)
               AND (
                    (status = 'pending_vetting' AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
                 OR (status = 'awaiting_cover'  AND (next_cover_attempt_at IS NULL OR next_cover_attempt_at <= ?))
                 OR (status IN ('approved', 'publish_blocked', 'publishing') AND (next_attempt_at IS NULL OR next_attempt_at <= ?))
               )
             ORDER BY id ASC
             LIMIT ?",
            [$now, $now, $now, $now, max(1, $limit)]
        )->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * For the Command Centre / KB publishing screens.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUi(?string $contentType, ?string $status, int $limit = 50, ?int $id = null): array
    {
        $builder = $this->db->table('reach_routine_submissions s')
            ->select('s.*, i.title, i.slug, i.workflow_status, i.approval_status, i.risk_level')
            ->join('reach_content_items i', 'i.id = s.content_item_id')
            ->orderBy('s.id', 'DESC')
            ->limit(max(1, min(200, $limit)));
        if ($contentType !== null && $contentType !== '') {
            $builder->where('s.content_type', $contentType);
        }
        if ($id !== null) {
            $builder->where('s.id', $id);
        }
        if ($status !== null && $status !== '') {
            $builder->whereIn('s.status', array_filter(array_map('trim', explode(',', $status))));
        }

        return array_map(fn (array $row): array => $this->decode($row), $builder->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (self::JSON_FIELDS as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            }
        }

        return $row;
    }
}
