<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Models\Intelligence\SearchMetricFactModel;
use App\Models\Intelligence\ContentMetricFactModel;

class AnomalyDetectionService
{
    private const POSITION_DECLINE_THRESHOLD = 3.0;
    private const SESSION_DECLINE_PCT        = 0.3;
    private const MIN_BASELINE_DAYS          = 14;

    public function __construct(
        private SearchMetricFactModel  $searchFactModel,
        private ContentMetricFactModel $contentFactModel,
        private AuditLogger            $auditLogger,
    ) {}

    public function detectSearchAnomalies(int $contentIdentityId, string $currentDate): array
    {
        $anomalies     = [];
        $baselineFrom  = date('Y-m-d', strtotime($currentDate . ' -28 days'));
        $baselineTo    = date('Y-m-d', strtotime($currentDate . ' -15 days'));
        $currentFrom   = date('Y-m-d', strtotime($currentDate . ' -14 days'));

        $baselineFacts = $this->searchFactModel->getForContent($contentIdentityId, $baselineFrom, $baselineTo);
        $currentFacts  = $this->searchFactModel->getForContent($contentIdentityId, $currentFrom, $currentDate);

        if (count($baselineFacts) < self::MIN_BASELINE_DAYS || count($currentFacts) < 3) {
            return [['type' => 'insufficient_data', 'message' => 'Not enough data for anomaly detection']];
        }

        $baselineAvgPos = $this->avg(array_column($baselineFacts, 'avg_position'));
        $currentAvgPos  = $this->avg(array_column($currentFacts, 'avg_position'));

        if ($currentAvgPos - $baselineAvgPos > self::POSITION_DECLINE_THRESHOLD) {
            $anomalies[] = [
                'type'             => 'position_decline',
                'severity'         => 'warning',
                'baseline_position' => round($baselineAvgPos, 2),
                'current_position'  => round($currentAvgPos, 2),
                'change'           => round($currentAvgPos - $baselineAvgPos, 2),
                'threshold'        => self::POSITION_DECLINE_THRESHOLD,
            ];
        }

        return $anomalies;
    }

    public function detectEngagementAnomalies(int $contentIdentityId, string $currentDate): array
    {
        $anomalies    = [];
        $baselineFrom = date('Y-m-d', strtotime($currentDate . ' -28 days'));
        $baselineTo   = date('Y-m-d', strtotime($currentDate . ' -15 days'));
        $currentFrom  = date('Y-m-d', strtotime($currentDate . ' -14 days'));

        $baselineFacts = $this->contentFactModel->getForContent($contentIdentityId, $baselineFrom, $baselineTo);
        $currentFacts  = $this->contentFactModel->getForContent($contentIdentityId, $currentFrom, $currentDate);

        if (count($baselineFacts) < self::MIN_BASELINE_DAYS || count($currentFacts) < 3) {
            return [['type' => 'insufficient_data', 'message' => 'Not enough data']];
        }

        $baselineSessions = array_sum(array_column($baselineFacts, 'sessions'));
        $currentSessions  = array_sum(array_column($currentFacts, 'sessions'));

        if ($baselineSessions > 0 && ($baselineSessions - $currentSessions) / $baselineSessions > self::SESSION_DECLINE_PCT) {
            $anomalies[] = [
                'type'               => 'session_decline',
                'severity'           => 'warning',
                'baseline_sessions'  => $baselineSessions,
                'current_sessions'   => $currentSessions,
                'decline_pct'        => round(($baselineSessions - $currentSessions) / $baselineSessions * 100, 1),
                'threshold_pct'      => self::SESSION_DECLINE_PCT * 100,
            ];
        }

        return $anomalies;
    }

    private function avg(array $values): float
    {
        if (empty($values)) return 0.0;
        return array_sum($values) / count($values);
    }
}
