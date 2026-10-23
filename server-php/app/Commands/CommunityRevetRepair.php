<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Community\Automation\CommunityRevetRepairService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark community:revet-repair` — the state re-vet corrections that
 * never reached aicountly.com left behind, and the one-off clean-up
 * (CommunityRevetRepairService). Dry run unless --apply; idempotent.
 *
 *   community:revet-repair                              report the affected answers (and what --apply would do)
 *   community:revet-repair --answer-ids=1,2,3,4,5,6,8   only these (reach_community_official_answers.id)
 *   community:revet-repair --apply                      restore / release as reported
 *   ... [--no-remote] [--json]
 *
 * After --apply, re-vet the released answers:
 *   php spark reach:content-revet --community --scope=live --type=none --answer-ids=<released>
 */
class CommunityRevetRepair extends BaseCommand
{
    use Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'community:revet-repair';
    protected $description = 'Report (and with --apply clean up) community answers whose re-vet correction gave up before reaching aicountly.com.';
    protected $usage       = 'community:revet-repair [--answer-ids=1,2] [--apply] [--no-remote] [--json]';
    protected $options     = [
        '--answer-ids' => 'Only these answers (reach_community_official_answers.id). Default: every answer whose re-vet correction gave up at publication.',
        '--apply'      => 'Restore / release as reported. Without it nothing is changed.',
        '--no-remote'  => 'Do not ask aicountly.com for the answer\'s live status (then nothing is restored).',
        '--json'       => 'Machine-readable output.',
    ];

    public function run(array $params): int
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($this->sparkOption('answer-ids', $params) ?? '')))));
        $result = (new CommunityRevetRepairService())->run(
            $ids,
            $this->sparkFlag('apply', $params),
            ! $this->sparkFlag('no-remote', $params),
        );

        if ($this->sparkFlag('json', $params)) {
            CLI::write(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return 0;
        }

        if ($result['items'] === []) {
            CLI::write('No community answer has a re-vet correction that gave up at publication.');
            return 0;
        }

        CLI::table(array_map(static function (array $i): array {
            if (! $i['exists']) {
                return [$i['answer_id'], 'missing', '', '', '', '', '', ''];
            }
            $d = $i['deployment'];
            $r = $i['reanswer'];

            return [
                $i['answer_id'],
                $i['status'] . ' v' . $i['approved_version'] . '/' . $i['current_version'],
                $d ? "#{$d['id']} v{$d['answer_version_number']} {$d['status']} {$d['attempt_count']}/{$d['max_attempts']}" : '-',
                $i['submission'] ? "#{$i['submission']['id']} {$i['submission']['status']}" : '-',
                $r ? "#{$r['id']} {$r['status']} ({$r['mode']}, {$r['attempts']})" : '-',
                $i['site'] === null ? 'not checked' : ($i['site']['error'] ?? ($i['site']['public_status'] . ' v' . $i['site']['public_version'] . ' r' . $i['site']['revision_number'])),
                implode('+', $i['actions']) ?: 'none',
                implode('+', $i['done'] ?? []) ?: ($i['note'] ?? ''),
            ];
        }, $result['items']), ['Answer', 'Reach (approved/current)', 'Latest publish deployment', 'Re-vet submission', 'Re-answer request', 'aicountly.com', 'Action', 'Done / note']);

        foreach ($result['items'] as $i) {
            if (! empty($i['deployment']['last_error'])) {
                CLI::write("  answer {$i['answer_id']}: deployment #{$i['deployment']['id']} last_error: " . mb_strimwidth((string) $i['deployment']['last_error'], 0, 200, '…'));
            }
        }

        $ids = implode(',', $result['released']);
        if ($result['dry_run']) {
            CLI::write('Dry run: nothing changed. Re-run with --apply to restore/release as listed.');
        } elseif ($ids !== '') {
            CLI::write("Released {$ids}. Re-vet them now:");
        }
        if ($ids !== '') {
            CLI::write("  php spark reach:content-revet --community --scope=live --type=none --answer-ids={$ids}");
        }

        return 0;
    }
}
