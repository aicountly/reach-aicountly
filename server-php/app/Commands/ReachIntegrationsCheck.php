<?php

declare(strict_types=1);

namespace App\Commands;

use App\Commands\Concerns\ParsesSparkOptions;
use App\Libraries\Settings\LiveIntegrationCheck;
use App\Libraries\Video\Automation\VideoProviders;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:integrations-check` — exercise Shotstack, YouTube,
 * DataForSEO, Gemini, OpenAI and OpenAI TTS against the live services with the
 * credentials saved in Settings → AI & Automation (or .env). Prints OK/FAIL
 * with a one-line reason per check, never a secret. Exit 0 when every
 * requested check passed, 1 when any failed, 2 on a usage error.
 *
 * docs/operations/LIVE_INTEGRATION_CHECKS.md
 */
class ReachIntegrationsCheck extends BaseCommand
{
    use ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:integrations-check';
    protected $description = 'Live-check Shotstack, YouTube, DataForSEO, Gemini, OpenAI and TTS with the saved credentials.';
    protected $usage       = 'reach:integrations-check [--only=shotstack,youtube,dataforseo,gemini,openai,tts] [--render-test] [--json]';
    protected $options     = [
        '--only'        => 'Comma-separated checks to run (default: all).',
        '--render-test' => 'Also submit a 3-second title-card render on the configured Shotstack environment and wait for it (stage: free/watermarked; v1: billed).',
        '--json'        => 'Print the report as JSON.',
    ];

    public function run(array $params): int
    {
        $json       = $this->sparkFlag('json', $params);
        $renderTest = $this->sparkFlag('render-test', $params);

        try {
            $targets = LiveIntegrationCheck::parseTargets($this->sparkOption('only', $params));
        } catch (\InvalidArgumentException $e) {
            CLI::error($e->getMessage());
            return 2;
        }
        if ($renderTest && ! in_array('shotstack', $targets, true)) {
            CLI::error('--render-test needs the shotstack check (drop --only, or include shotstack in it).');
            return 2;
        }

        $progress = $json ? null : static function (string $target, string $line): void {
            CLI::write('        … ' . $target . ': ' . $line);
        };
        if (! $json) {
            CLI::write('Reach live integration checks — ' . implode(', ', $targets) . ($renderTest ? ' (with Shotstack test render)' : ''));
        }

        $report = (new LiveIntegrationCheck(VideoProviders::http()))->run($targets, $renderTest, $progress);

        if ($json) {
            CLI::write((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach (self::lines($report) as $line) {
                CLI::write($line);
            }
        }

        return $report['ok'] ? 0 : 1;
    }

    /**
     * Human-readable report: one OK/FAIL line per check, then its details.
     *
     * @param array{ok: bool, passed: int, failed: int, results: list<array<string, mixed>>} $report
     * @return list<string>
     */
    public static function lines(array $report): array
    {
        $out = [];
        foreach ($report['results'] as $r) {
            $out[] = sprintf('  %-4s  %-10s  %s', $r['ok'] ? 'OK' : 'FAIL', $r['target'], $r['message']);
            foreach (self::flatten((array) ($r['detail'] ?? [])) as $k => $v) {
                $out[] = sprintf('        %s: %s', $k, $v);
            }
        }
        $out[] = sprintf('%d passed, %d failed.', $report['passed'], $report['failed']);
        return $out;
    }

    /** @return array<string, string> */
    private static function flatten(array $detail, string $prefix = ''): array
    {
        $out = [];
        foreach ($detail as $k => $v) {
            $key = $prefix . $k;
            if (is_array($v)) {
                $out += self::flatten($v, $key . '.');
            } elseif ($v !== null) {
                $out[$key] = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
            }
        }
        return $out;
    }
}
