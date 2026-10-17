<?php

declare(strict_types=1);

namespace App\Libraries\Reports;

use App\Libraries\Ga4AnalyticsClient;

/**
 * GA4 figures for the daily report: users and sessions for one day against
 * the previous 7-day average, and the top session sources for that day.
 * Reads the marketing property (GA4_PROPERTY_ID_MARKETING +
 * GOOGLE_SERVICE_ACCOUNT_JSON_MARKETING), the one that tracks aicountly.com.
 */
class Ga4DailySnapshot
{
    public function isConfigured(): bool
    {
        return $this->propertyId() !== '' && $this->keyPath() !== '';
    }

    /**
     * @return array{configured:bool, error?:string, date?:string, users?:int, sessions?:int, baseline_users?:float, baseline_sessions?:float, sources?:list<array{source:string, sessions:int, users:int}>}
     */
    public function fetch(string $date): array
    {
        if (! $this->isConfigured()) {
            return ['configured' => false];
        }

        $token = Ga4AnalyticsClient::getAccessTokenFromPath($this->keyPath());
        if ($token === null) {
            return ['configured' => true, 'error' => 'GA4 service-account authentication failed (check GOOGLE_SERVICE_ACCOUNT_JSON_MARKETING).'];
        }

        $baseFrom = date('Y-m-d', strtotime($date . ' -7 days'));
        $baseTo   = date('Y-m-d', strtotime($date . ' -1 day'));

        $daily = Ga4AnalyticsClient::runReport($token, $this->propertyId(), [
            'dateRanges' => [['startDate' => $baseFrom, 'endDate' => $date]],
            'dimensions' => [['name' => 'date']],
            'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions']],
        ]);
        if ($daily === null) {
            return ['configured' => true, 'error' => 'GA4 Data API request failed (property ' . $this->propertyId() . ').'];
        }

        $users = 0;
        $sessions = 0;
        $baseUsers = 0;
        $baseSessions = 0;
        $target = str_replace('-', '', $date);
        foreach (($daily['rows'] ?? []) as $row) {
            $day = (string) ($row['dimensionValues'][0]['value'] ?? '');
            $u   = (int) ($row['metricValues'][0]['value'] ?? 0);
            $s   = (int) ($row['metricValues'][1]['value'] ?? 0);
            if ($day === $target) {
                $users = $u;
                $sessions = $s;
            } else {
                $baseUsers += $u;
                $baseSessions += $s;
            }
        }

        $sourcesReport = Ga4AnalyticsClient::runReport($token, $this->propertyId(), [
            'dateRanges' => [['startDate' => $date, 'endDate' => $date]],
            'dimensions' => [['name' => 'sessionSourceMedium']],
            'metrics'    => [['name' => 'sessions'], ['name' => 'activeUsers']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => 8,
        ]);
        $sources = [];
        foreach (($sourcesReport['rows'] ?? []) as $row) {
            $sources[] = [
                'source'   => (string) ($row['dimensionValues'][0]['value'] ?? '(not set)'),
                'sessions' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users'    => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        return [
            'configured'        => true,
            'date'              => $date,
            'users'             => $users,
            'sessions'          => $sessions,
            'baseline_users'    => round($baseUsers / 7, 1),
            'baseline_sessions' => round($baseSessions / 7, 1),
            'sources'           => $sources,
        ];
    }

    private function propertyId(): string
    {
        return trim((string) (env('GA4_PROPERTY_ID_MARKETING') ?? ''));
    }

    private function keyPath(): string
    {
        return trim((string) (env('GOOGLE_SERVICE_ACCOUNT_JSON_MARKETING') ?? ''));
    }
}
