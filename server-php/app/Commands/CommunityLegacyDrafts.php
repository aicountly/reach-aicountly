<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Community\Automation\CommunityLegacyDraftService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark community:legacy-drafts` — list (default) or clear the answers
 * the old in-Reach drafting left stuck before publication.
 *
 *   community:legacy-drafts                        list them
 *   community:legacy-drafts --clear                show what would be cleared (dry run)
 *   community:legacy-drafts --clear --confirm      archive them
 *   ... [--older-than=48] [--answer=<uuid>] [--json]
 *
 * Clearing archives the answer — nothing is deleted and none of these was
 * public — so its question shows up again in community-plan's open questions
 * for the reach-community-qa routine to answer.
 */
class CommunityLegacyDrafts extends BaseCommand
{
    use Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'community:legacy-drafts';
    protected $description = 'List or clear community answers stuck from the old in-Reach drafting.';
    protected $usage       = 'community:legacy-drafts [--clear [--confirm]] [--older-than=<hours>] [--answer=<uuid>] [--json]';
    protected $options     = [
        '--clear'      => 'Clear the listed drafts (dry run unless --confirm).',
        '--confirm'    => 'With --clear: actually archive them.',
        '--older-than' => 'Only drafts untouched for at least this many hours.',
        '--answer'     => 'Only this answer UUID.',
        '--json'       => 'Machine-readable output.',
    ];

    public function run(array $params): int
    {
        $service = new CommunityLegacyDraftService();
        $asJson  = $this->sparkFlag('json', $params);

        if (! $this->sparkFlag('clear', $params)) {
            $items = $service->list();
            if ($asJson) {
                CLI::write(json_encode(['stuck' => count($items), 'items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return 0;
            }
            $this->table($items);
            CLI::write(count($items) . ' stuck legacy draft(s). Clear with --clear --confirm.');
            return 0;
        }

        $result = $service->clear(
            ! $this->sparkFlag('confirm', $params),
            max(0, (int) ($this->sparkOption('older-than', $params, '0') ?? '0')),
            $this->sparkOption('answer', $params),
        );

        if ($asJson) {
            CLI::write(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $this->table($result['items']);
        CLI::write($result['dry_run']
            ? count($result['items']) . ' draft(s) would be archived. Re-run with --confirm to clear them.'
            : $result['cleared'] . ' of ' . count($result['items']) . ' draft(s) archived; their questions are open for the routine again.');

        return 0;
    }

    /** @param list<array<string, mixed>> $items */
    private function table(array $items): void
    {
        if ($items === []) {
            return;
        }
        CLI::table(array_map(static fn (array $i): array => [
            $i['answer_id'],
            $i['status'],
            $i['versions'],
            $i['age_hours'] . 'h',
            $i['desk'],
            mb_strimwidth($i['question'], 0, 60, '…'),
            $i['result'] ?? '',
        ], $items), ['Answer', 'Status', 'Versions', 'Idle', 'Desk', 'Question', 'Result']);
    }
}
