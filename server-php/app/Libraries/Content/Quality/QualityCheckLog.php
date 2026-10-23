<?php

declare(strict_types=1);

namespace App\Libraries\Content\Quality;

use CodeIgniter\Database\BaseConnection;

/** reach_content_quality_checks writer/reader. A logging failure never decides an outcome. */
final class QualityCheckLog
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @param array<string, mixed> $row */
    public function record(array $row): ?int
    {
        try {
            foreach (['heuristic_flags', 'reasons'] as $json) {
                $row[$json] = json_encode(array_values((array) ($row[$json] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $row['dry_run'] = ! empty($row['dry_run']) ? 't' : 'f';
            $this->db->table('reach_content_quality_checks')->insert($row + ['created_at' => date('Y-m-d H:i:s')]);

            return (int) $this->db->insertID() ?: null;
        } catch (\Throwable $e) {
            log_message('error', '[ContentQuality] could not record check: ' . $e->getMessage());

            return null;
        }
    }

    /** True when this exact version was already vetted by this trigger (so a daily sweep does not re-vet it). */
    public function alreadyChecked(string $subjectType, int $subjectId, int $versionId, string $trigger): bool
    {
        try {
            return $this->db->table('reach_content_quality_checks')
                ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
                ->where('content_version_id', $versionId)->where('trigger_source', $trigger)
                ->where('dry_run', false)
                ->whereIn('decision', ['pass', 'revise', 'fail'])
                ->countAllResults() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Counts for the daily report: checks since $since, by decision and action.
     *
     * @return array{checked: int, passed: int, failed: int, unpublished: int, queued_regeneration: int, unavailable: int}
     */
    public function summarySince(string $since): array
    {
        $out = ['checked' => 0, 'passed' => 0, 'failed' => 0, 'unpublished' => 0, 'queued_regeneration' => 0, 'unavailable' => 0];
        try {
            foreach ($this->db->table('reach_content_quality_checks')->select('decision, action')
                ->where('created_at >=', $since)->where('dry_run', false)->get()->getResultArray() as $r) {
                $out['checked']++;
                $out['passed']      += $r['decision'] === 'pass' ? 1 : 0;
                $out['failed']      += $r['decision'] === 'fail' ? 1 : 0;
                $out['unavailable'] += $r['decision'] === 'unavailable' ? 1 : 0;
                // Blogs/KB: unpublished(+queued_regeneration); community answers: withdrawn+requeued, kept_live+requeued.
                $out['unpublished'] += str_contains((string) $r['action'], 'unpublished') || str_starts_with((string) $r['action'], 'withdrawn') ? 1 : 0;
                $out['queued_regeneration'] += str_contains((string) $r['action'], 'regeneration') || str_contains((string) $r['action'], 'requeued') ? 1 : 0;
            }
        } catch (\Throwable) {
        }

        return $out;
    }
}
