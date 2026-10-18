<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Email\EmailDispatcher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:email-providers [--refresh] [--send=you@example.com] [--resend]`
 *
 * Shows which email providers Reach sends through (Console's registry for reach, else the .env
 * EMAIL_API_KEY last resort) — provider codes only, never a key or password. --refresh drops the
 * cached Console answer first. --send mails a short test message through them (normal order, or
 * fallback-first with --resend). Exit code 1 when no provider is available or the test was not
 * accepted.
 */
class ReachEmailProviders extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:email-providers';
    protected $description = 'List the email providers in use (names only) and optionally send a test mail.';
    protected $usage       = 'reach:email-providers [--refresh] [--send=email] [--resend]';

    public function run(array $params): int
    {
        if ($this->sparkFlag('refresh', $params)) {
            $dir = trim((string) env('EMAIL_PROVIDER_CACHE_DIR', '')) ?: rtrim(WRITEPATH, '/') . '/cache/email-providers';
            array_map('unlink', glob($dir . '/email_providers_*') ?: []);
        }

        $dispatcher = EmailDispatcher::instance();
        $summary    = $dispatcher->resolver()->summary();
        $out        = ['source' => $summary['source'], 'line' => $summary['line'], 'note' => $summary['note'], 'providers' => $summary['providers']];
        $ok         = $summary['providers'] !== [];

        $to = trim((string) ($this->sparkOption('send', $params) ?? ''));
        if ($to !== '') {
            $resend = $this->sparkFlag('resend', $params);
            $stamp  = \App\Libraries\Support\DisplayTime::format(time());
            $result = $dispatcher->send(
                $to,
                'Reach email provider test — ' . $stamp,
                '<p>This is a test from <strong>reach.aicountly.org</strong> (' . ($resend ? 'resend order' : 'normal order') . ', ' . esc($stamp) . ').</p>',
                null,
                ['resend' => $resend],
            );
            $out['send'] = [
                'to' => $to, 'resend' => $resend, 'ok' => $result['ok'], 'provider' => $result['provider'],
                'id' => $result['id'], 'attempts' => $result['attempts'], 'error' => $result['error'],
            ];
            $ok = $result['ok'];
        }

        CLI::write((string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $ok ? 0 : 1;
    }
}
