<?php

declare(strict_types=1);

namespace App\Libraries\Bot\Plan;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * reach_bot_plans / reach_bot_plan_items: versioned plan history.
 *
 * save() writes a new version only when the plan's numbers changed
 * (PlanGenerator::fingerprint); an unchanged regeneration just records that
 * it was checked. Old versions are never rewritten, so "what did the bot plan
 * for 12 October, as of that day" always has an answer.
 */
class PlanStore
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $summary
     * @return array{plan_id:int, version:int, created:bool}
     */
    public function save(string $type, string $start, string $end, array $items, array $summary, ?\DateTimeImmutable $now = null): array
    {
        $at     = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        $fp     = PlanGenerator::fingerprint($items);
        $latest = $this->db->table('reach_bot_plans')
            ->where('period_type', $type)->where('period_start', $start)
            ->orderBy('version', 'DESC')->limit(1)->get()->getRowArray();

        if ($latest && $latest['inputs_sha256'] === $fp) {
            $this->db->table('reach_bot_plans')->where('id', $latest['id'])->update(['last_checked_at' => $at]);

            return ['plan_id' => (int) $latest['id'], 'version' => (int) $latest['version'], 'created' => false];
        }

        $version = $latest ? (int) $latest['version'] + 1 : 1;
        $this->db->transStart();
        $this->db->table('reach_bot_plans')->insert([
            'period_type'     => $type,
            'period_start'    => $start,
            'period_end'      => $end,
            'version'         => $version,
            'generated_at'    => $at,
            'last_checked_at' => $at,
            'inputs_sha256'   => $fp,
            'method_version'  => PlanGenerator::METHOD_VERSION,
            'summary_json'    => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at'      => $at,
        ]);
        $planId = (int) $this->db->insertID();
        foreach ($items as $item) {
            $row = $item;
            $row['plan_id']       = $planId;
            $row['source_detail'] = json_encode($item['source_detail'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->db->table('reach_bot_plan_items')->insert($row);
        }
        $this->db->transComplete();

        return ['plan_id' => $planId, 'version' => $version, 'created' => true];
    }

    /** @return array<string, mixed>|null the latest version of a period, with items keyed by metric */
    public function latest(string $type, string $start): ?array
    {
        $row = $this->db->table('reach_bot_plans')
            ->where('period_type', $type)->where('period_start', $start)
            ->orderBy('version', 'DESC')->limit(1)->get()->getRowArray();

        return $row ? $this->withItems($row) : null;
    }

    /** @return list<array{version:int, generated_at:string, plan_id:int}> */
    public function versions(string $type, string $start, int $limit = 20): array
    {
        return array_map(static fn (array $r): array => ['plan_id' => (int) $r['id'], 'version' => (int) $r['version'], 'generated_at' => (string) $r['generated_at']],
            $this->db->table('reach_bot_plans')->select('id, version, generated_at')
                ->where('period_type', $type)->where('period_start', $start)
                ->orderBy('version', 'DESC')->limit($limit)->get()->getResultArray());
    }

    /**
     * The day plan of record for each date: the latest version generated
     * before that IST day ended (a later regeneration does not rewrite it).
     *
     * @return array<string, array<string, array<string, mixed>>> date => metric => item
     */
    public function dayPlans(string $from, string $to): array
    {
        $rows = $this->db->query(
            "SELECT DISTINCT ON (period_start) id, period_start::text AS d
             FROM reach_bot_plans
             WHERE period_type = 'day' AND period_start BETWEEN ? AND ?
               AND generated_at < ((period_start + 1)::timestamp AT TIME ZONE 'Asia/Kolkata')
             ORDER BY period_start, version DESC",
            [$from, $to]
        )->getResultArray();
        if ($rows === []) {
            return [];
        }
        $byPlan = array_column($rows, 'd', 'id');
        $out    = [];
        foreach ($this->db->table('reach_bot_plan_items')->whereIn('plan_id', array_keys($byPlan))->get()->getResultArray() as $item) {
            $out[$byPlan[$item['plan_id']]][(string) $item['metric_key']] = self::decodeItem($item);
        }
        ksort($out);

        return $out;
    }

    public function firstPlanDate(): ?string
    {
        $row = $this->db->query("SELECT MIN(period_start)::text AS d FROM reach_bot_plans WHERE period_type = 'day'")->getRowArray();

        return $row['d'] ?? null;
    }

    /** @param array<string, mixed> $row */
    private function withItems(array $row): array
    {
        $row['summary_json'] = is_string($row['summary_json']) ? (json_decode($row['summary_json'], true) ?: []) : (array) $row['summary_json'];
        $items = [];
        foreach ($this->db->table('reach_bot_plan_items')->where('plan_id', $row['id'])->orderBy('id')->get()->getResultArray() as $item) {
            $items[(string) $item['metric_key']] = self::decodeItem($item);
        }
        $row['items'] = $items;

        return $row;
    }

    /** @param array<string, mixed> $item */
    public static function decodeItem(array $item): array
    {
        foreach (['target_value', 'base_target', 'low_value', 'high_value'] as $k) {
            $item[$k] = $item[$k] === null ? null : (float) $item[$k];
        }
        $item['source_detail'] = is_string($item['source_detail'] ?? null) ? (json_decode($item['source_detail'], true) ?: []) : (array) ($item['source_detail'] ?? []);

        return $item;
    }
}
