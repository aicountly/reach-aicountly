<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Insights;

use CodeIgniter\Database\BaseConnection;

/**
 * Weekly growth-strategy memos written by the reach-growth-strategy Claude
 * routine (docs/automation/routines/reach-growth-strategy.md) and shown on
 * the AI Insights page. Internal only — never published.
 */
final class StrategyMemoService
{
    public const MAX_BODY = 200_000;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * @param array<string, mixed> $in
     * @return array{memo: array<string, mixed>, duplicate: bool}
     * @throws \InvalidArgumentException with a message naming the bad field
     */
    public function store(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        $body  = (string) ($in['body_markdown'] ?? '');
        if ($title === '' || mb_strlen($title) > 300) {
            throw new \InvalidArgumentException('title is required (max 300 characters).');
        }
        if (mb_strlen(trim($body)) < 200) {
            throw new \InvalidArgumentException('body_markdown is required (at least 200 characters of markdown).');
        }
        if (strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('body_markdown is too long (max 200 KB).');
        }
        $dates = [];
        foreach (['period_start', 'period_end'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) === false) {
                throw new \InvalidArgumentException("{$k} must be YYYY-MM-DD.");
            }
            $dates[$k] = $v !== '' ? $v : null;
        }
        $runId = trim((string) ($in['routine_run_id'] ?? ''));
        if (mb_strlen($runId) > 191) {
            throw new \InvalidArgumentException('routine_run_id is too long (max 191 characters).');
        }
        $sources = array_values(array_slice(array_filter(array_map(static fn ($s) => is_scalar($s) ? mb_substr(trim((string) $s), 0, 500) : '', (array) ($in['sources'] ?? []))), 0, 50));

        if ($runId !== '') {
            $existing = $this->db->table('reach_strategy_memos')->where('routine_run_id', $runId)->get()->getRowArray();
            if ($existing) {
                return ['memo' => $this->decode($existing), 'duplicate' => true];
            }
        }

        $latest = $this->db->table('reach_ai_insight_reports')->select('id')->where('status', 'completed')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $this->db->table('reach_strategy_memos')->insert([
            'title'              => $title,
            'body_markdown'      => $body,
            'period_start'       => $dates['period_start'],
            'period_end'         => $dates['period_end'],
            'routine_run_id'     => $runId !== '' ? $runId : null,
            'model'              => mb_substr(trim((string) ($in['model'] ?? '')), 0, 120) ?: null,
            'insights_report_id' => $latest ? (int) $latest['id'] : null,
            'sources_json'       => json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'body_sha256'        => hash('sha256', $body),
        ]);

        return ['memo' => $this->find((int) $this->db->insertID()) ?? [], 'duplicate' => false];
    }

    /** @return list<array<string, mixed>> without bodies */
    public function list(int $limit = 20): array
    {
        $rows = $this->db->table('reach_strategy_memos')
            ->select('id, title, period_start, period_end, routine_run_id, model, insights_report_id, created_at, LENGTH(body_markdown) AS body_chars', false)
            ->orderBy('id', 'DESC')->limit(max(1, min(100, $limit)))->get()->getResultArray();

        return array_map(static function (array $r): array {
            $r['id']         = (int) $r['id'];
            $r['body_chars'] = (int) $r['body_chars'];
            $r['insights_report_id'] = $r['insights_report_id'] !== null ? (int) $r['insights_report_id'] : null;
            return $r;
        }, $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->table('reach_strategy_memos')->where('id', $id)->get()->getRowArray();

        return $row ? $this->decode($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $src = $row['sources_json'] ?? '[]';
        $row['sources'] = is_array($src) ? $src : (json_decode((string) $src, true) ?: []);
        unset($row['sources_json']);
        $row['id'] = (int) $row['id'];
        $row['insights_report_id'] = isset($row['insights_report_id']) ? (int) $row['insights_report_id'] : null;

        return $row;
    }
}
