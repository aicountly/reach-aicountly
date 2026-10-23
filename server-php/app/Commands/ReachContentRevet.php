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
 * does. --dry-run changes nothing and prints what would happen.
 *
 * Live items: a Gemini reject withdraws the page and queues a same-slug
 * rewrite; a revise that Gemini approved after correcting it is applied
 * (LiveRevisionApplier): new current version, approved under the
 * SystemApprover, republished at the same URL (`revision_applied+republish_queued`,
 * or `revision_failed` with the reason; dry run: `apply_revision+republish`).
 * The slug never changes and a revise never takes a page down. A revise with
 * no usable corrected text stays `flagged_for_owner`. To fix given posts:
 *   php spark reach:content-revet --scope=live --type=blog --ids=2,32 --dry-run
 *   php spark reach:content-revet --scope=live --type=blog --ids=2,32
 *
 * Options:
 *   --dry-run            report only
 *   --scope=all|unpublished|live
 *   --type=blog,knowledge_base,release_note,workflow_guide
 *   --ids=1,5            only these content items
 *   --limit=200
 *   --heuristics-only    no Gemini calls; list what the cheap checks flag (implies --dry-run)
 *   --community          also vet live community answers (report only)
 *   --json               machine-readable output
 */
class ReachContentRevet extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:content-revet';
    protected $description = 'Re-vet approved-unpublished and live content; retire, unpublish and queue regeneration for what fails.';
    protected $usage       = 'reach:content-revet [--dry-run] [--scope=all|unpublished|live] [--type=blog,...] [--ids=1,5] [--limit=200] [--heuristics-only] [--community] [--json]';

    public function run(array $params): int
    {
        @set_time_limit(0);
        $heuristicsOnly = $this->sparkOption('heuristics-only', $params) !== null;
        $dryRun         = $heuristicsOnly || $this->sparkOption('dry-run', $params) !== null;
        $types          = $this->list($this->sparkOption('type', $params));
        $ids            = array_map('intval', $this->list($this->sparkOption('ids', $params)));

        $report = (new ContentRevetService())->run([
            'types'           => $types !== [] ? $types : ContentRevetService::ITEM_TYPES,
            'scope'           => (string) ($this->sparkOption('scope', $params, 'all') ?? 'all'),
            'dry_run'         => $dryRun,
            'heuristics_only' => $heuristicsOnly,
            'ids'             => $ids,
            'limit'           => (int) ($this->sparkOption('limit', $params, '200') ?? '200'),
            'community'       => $this->sparkOption('community', $params) !== null,
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
