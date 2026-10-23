<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Content\Quality\ContentRevetService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:content-revet [--dry-run]` — re-vet every approved-but-
 * unpublished and every live blog / KB article / release note / workflow
 * guide (and, with --community, live community answers) with the vetting
 * model, then clean up: see ContentRevetService for exactly what each verdict
 * does. --dry-run changes nothing and prints, per row, what a real run would
 * do (`would: …`, also counted in the totals as would:<step>).
 *
 * Live community answers (--community), fixed by the bot with nobody in the loop:
 *   reject              → withdraw+requeue: unpublished on aicountly.com and marked
 *                         withdrawn in Reach (reversible), then offered to the
 *                         reach-community-qa routine as a priority re-answer (no
 *                         quota); the new answer goes up at the same URL.
 *   revise, with text   → correct: the vetting model's revision is published as a
 *                         correction version ("Updated for accuracy.") through
 *                         vet → SystemApprover → publish → verify; the current
 *                         text stays live until then.
 *   revise, no text     → keep_live+requeue: stays live, queued for a re-answer.
 * Actions reported: withdrawn+requeued, corrected, correction_pending,
 * correction_failed+requeued, kept_live+requeued, held_high_risk_left_as_is,
 * withdraw_failed, requeue_failed … A failed step is reported, never hidden,
 * and does not stop the run. To vet only community answers, pass --community
 * with --scope=live --type=none.
 *
 * Options:
 *   --dry-run            report only
 *   --scope=all|unpublished|live
 *   --type=blog,knowledge_base,release_note,workflow_guide   (none = no content items)
 *   --ids=1,5            only these content items
 *   --answer-ids=2,4     with --community: only these community answers (reach_community_official_answers.id)
 *   --limit=200
 *   --heuristics-only    no Gemini calls; list what the cheap checks flag (implies --dry-run)
 *   --community          also vet live community answers, and fix them unless --dry-run
 *   --json               machine-readable output
 */
class ReachContentRevet extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:content-revet';
    protected $description = 'Re-vet approved-unpublished and live content (and, with --community, live community answers); retire, unpublish, correct and queue rewrites for what fails.';
    protected $usage       = 'reach:content-revet [--dry-run] [--scope=all|unpublished|live] [--type=blog,...|none] [--ids=1,5] [--answer-ids=2,4] [--limit=200] [--heuristics-only] [--community] [--json]';

    public function run(array $params): int
    {
        @set_time_limit(0);
        $heuristicsOnly = $this->sparkOption('heuristics-only', $params) !== null;
        $dryRun         = $heuristicsOnly || $this->sparkOption('dry-run', $params) !== null;
        $types          = $this->list($this->sparkOption('type', $params));
        $ids            = array_map('intval', $this->list($this->sparkOption('ids', $params)));
        $answerIds      = array_map('intval', $this->list($this->sparkOption('answer-ids', $params)));

        $report = (new ContentRevetService())->run([
            'types'           => $types === ['none'] ? [] : ($types !== [] ? $types : ContentRevetService::ITEM_TYPES),
            'scope'           => (string) ($this->sparkOption('scope', $params, 'all') ?? 'all'),
            'dry_run'         => $dryRun,
            'heuristics_only' => $heuristicsOnly,
            'ids'             => $ids,
            'limit'           => (int) ($this->sparkOption('limit', $params, '200') ?? '200'),
            'community'       => $this->sparkOption('community', $params) !== null,
            'answer_ids'      => $answerIds,
            'trigger'         => 'command',
        ]);

        if ($this->sparkOption('json', $params) !== null) {
            CLI::write(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return 0;
        }

        CLI::write(($dryRun ? 'DRY RUN — nothing changed. ' : '') . 'Checked ' . $report['totals']['checked'] . ' item(s).', 'yellow');
        $rows = [];
        foreach ($report['items'] as $r) {
            $rows[] = [
                $r['id'], $r['type'], $r['scope'], mb_strimwidth((string) $r['title'], 0, 48, '…'),
                $r['decision'] . ($r['vetting'] ? " ({$r['vetting']})" : ''),
                $dryRun ? ('would: ' . ($r['would'] ?? 'none')) : $r['action'],
                mb_strimwidth(implode(' | ', array_merge($r['flags'], $r['reasons'])), 0, 140, '…'),
            ];
        }
        if ($rows !== []) {
            CLI::table($rows, ['ID', 'Type', 'Scope', 'Title', 'Verdict', 'Action', 'Flags / reasons']);
        }
        foreach ($report['totals'] as $k => $v) {
            CLI::write(str_pad($k, 36) . $v);
        }

        return 0;
    }

    /** @return list<string> */
    private function list(?string $value): array
    {
        if ($value === null || $value === '1') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
