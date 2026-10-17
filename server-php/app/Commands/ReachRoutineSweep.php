<?php

namespace App\Commands;

use App\Libraries\Automation\Routine\RoutineContentPipeline;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:routine-sweep [--limit=25] [--id=N]` — run the routine
 * blog/KB pipeline now (normally the reach.routine_content_sweep job does
 * this every five minutes). --id processes one submission, clearing its
 * backoff like the UI's Retry.
 */
class ReachRoutineSweep extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:routine-sweep';
    protected $description = 'Vet, cover, publish and verify pending Claude-routine blog and KB submissions.';
    protected $usage       = 'reach:routine-sweep [--limit=25] [--id=N]';

    public function run(array $params): int
    {
        @set_time_limit(0);
        $pipeline = new RoutineContentPipeline();
        $id       = (int) ($this->sparkOption('id', $params, '0') ?? '0');

        $result = $id > 0
            ? ['submission' => $id, 'status' => $pipeline->retry($id)['status']]
            : $pipeline->sweep(max(1, (int) ($this->sparkOption('limit', $params, '25') ?? '25')));

        CLI::write(json_encode(['event' => 'routine_sweep.completed', 'ts' => gmdate('c')] + $result, JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
