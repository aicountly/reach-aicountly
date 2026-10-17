<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Reports\DailyReportService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:daily-report [--date=YYYY-MM-DD] [--send] [--to=a@x.com,b@y.com]`
 *
 * Without --send: builds and stores the report (status `generated`) and
 * prints its summary — safe to run any time. With --send: also emails it
 * (to --to when given, otherwise reports.daily.recipients). Exit code 1 when
 * a send was requested and did not fully succeed.
 */
class ReachDailyReport extends BaseCommand
{
    use \App\Commands\Concerns\ParsesSparkOptions;

    protected $group       = 'Reach';
    protected $name        = 'reach:daily-report';
    protected $description = 'Build (and optionally email) the daily analytics report.';
    protected $usage       = 'reach:daily-report [--date=YYYY-MM-DD] [--send] [--to=email1,email2]';

    public function run(array $params): int
    {
        $date = (string) ($this->sparkOption('date', $params) ?? '');
        if ($date === '') {
            $date = DailyReportService::todayIst();
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            CLI::error('--date must be YYYY-MM-DD');

            return 1;
        }

        $send = $this->sparkFlag('send', $params);
        $to   = (string) ($this->sparkOption('to', $params) ?? '');
        $toList = $to === '' ? null : array_values(array_filter(array_map('trim', explode(',', $to))));

        try {
            $service = new DailyReportService();
            $row     = $send ? $service->send($date, $toList, 'cli') : $service->generate($date, 'cli');
        } catch (\Throwable $e) {
            CLI::error('daily-report failed: ' . $e->getMessage());

            return 1;
        }

        CLI::write(json_encode([
            'report_id' => (int) $row['id'],
            'date'      => $row['report_date'],
            'status'    => $row['status'],
            'subject'   => $row['subject'],
            'sent_to'   => $row['sent_to'],
            'error'     => $row['error'],
            'todo'      => $row['summary_json']['sections']['todo']['items'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $send && $row['status'] !== 'sent' ? 1 : 0;
    }
}
