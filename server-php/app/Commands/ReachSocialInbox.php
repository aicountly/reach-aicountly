<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Distribution\SocialInbox\FlowTicketClient;
use App\Libraries\Distribution\SocialInbox\SocialInboxService;
use App\Libraries\Video\Automation\VideoProviders;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:social-inbox` — run the social inbox bot now (the same work
 * reach.social_inbox_poll does every 10 minutes), or check the Flow link.
 *
 *   (no option)     poll social-api.ai, then handle up to --limit new items
 *   --poll-only     only read and store new comments / messages
 *   --process-only  only handle what is already stored
 *   --item=ID       handle one stored item now
 *   --flow-check    prove the Reach → Flow key (an empty body must answer 422); lodges nothing
 *   --json          print the result as JSON
 *
 * docs/operations/SOCIAL_API.md → Social inbox bot.
 */
class ReachSocialInbox extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:social-inbox';
    protected $description = 'Run the social inbox bot now (read comments/DMs, answer, lodge complaints in Flow), or --flow-check.';
    protected $usage       = 'reach:social-inbox [--poll-only|--process-only|--item=ID|--flow-check] [--limit=25] [--json]';
    protected $options     = [
        '--poll-only'    => 'Only read and store new items.',
        '--process-only' => 'Only handle stored items.',
        '--item'         => 'Handle one stored item (reach_social_inbox.id).',
        '--flow-check'   => 'Check the Reach → Flow key without lodging anything.',
        '--limit'        => 'Items to handle (default 25).',
        '--json'         => 'Print JSON.',
    ];

    public function run(array $params): int
    {
        $json  = $this->sparkFlag('json', $params);
        $limit = max(1, min(200, (int) ($this->sparkOption('limit', $params, '25') ?? '25')));

        if ($this->sparkFlag('flow-check', $params)) {
            $r = FlowTicketClient::fromConfig(VideoProviders::http())->check();
            $this->emit($r, $json);
            return $r['ok'] ? 0 : 1;
        }

        $svc  = SocialInboxService::fromConfig();
        $item = $this->sparkOption('item', $params);
        if ($item !== null && $item !== '') {
            $status = $svc->processOne((int) $item);
            $this->emit(['item' => (int) $item, 'status' => $status ?? 'not found'], $json);
            return $status === null ? 1 : 0;
        }

        $result = match (true) {
            $this->sparkFlag('poll-only', $params)    => ['poll' => $svc->poll()],
            $this->sparkFlag('process-only', $params) => ['process' => $svc->process($limit)],
            default                                   => $svc->run($limit),
        };
        $this->emit($result, $json);
        $status = $result['status'] ?? ($result['poll']['status'] ?? 'ok');

        return in_array($status, ['ok', 'disabled'], true) ? 0 : 1;
    }

    private function emit(array $data, bool $json): void
    {
        if ($json) {
            CLI::write((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        foreach (self::flatten($data) as $k => $v) {
            CLI::write(sprintf('  %s: %s', $k, $v));
        }
    }

    /** @return array<string, string> */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $out += $v === [] ? [$prefix . $k => '—'] : self::flatten($v, $prefix . $k . '.');
            } else {
                $out[$prefix . $k] = is_bool($v) ? ($v ? 'yes' : 'no') : (string) ($v ?? '—');
            }
        }
        return $out;
    }
}
