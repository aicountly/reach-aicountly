<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Generation;

/**
 * Phase 3 — Budget enforcement for AI generation.
 *
 * Checks daily/monthly budgets before allowing a generation run.
 * AI must never bypass budget checks.
 */
class AiBudgetService
{
    /**
     * Check if a generation would exceed any applicable budgets.
     *
     * @return BudgetCheckResult
     */
    public function check(array $context): BudgetCheckResult
    {
        $db = db_connect();

        $today  = date('Y-m-d');
        $month  = date('Y-m');

        $scope_checks = [
            ['global',       'global'],
            ['provider',     $context['provider_key'] ?? 'unknown'],
            ['model',        $context['model_key'] ?? 'unknown'],
            ['content_type', $context['content_type'] ?? 'unknown'],
        ];

        foreach ($scope_checks as [$scope, $ref]) {
            // Daily
            $budget = $db->table('reach_ai_budgets')
                ->where('scope_type', $scope)
                ->where('scope_reference', $ref)
                ->where('period_type', 'daily')
                ->where('enabled', true)
                ->get()
                ->getRowArray();

            if ($budget) {
                $used = $this->dailyUsage($db, $scope, $ref, $today);
                if ($budget['hard_limit'] > 0 && $used >= (float) $budget['hard_limit']) {
                    return new BudgetCheckResult(false, true, $scope, $ref, 'daily', $used, (float) $budget['hard_limit']);
                }
                if ($budget['warning_limit'] > 0 && $used >= (float) $budget['warning_limit']) {
                    return new BudgetCheckResult(true, false, $scope, $ref, 'daily', $used, (float) $budget['hard_limit'], true);
                }
            }

            // Monthly
            $budget = $db->table('reach_ai_budgets')
                ->where('scope_type', $scope)
                ->where('scope_reference', $ref)
                ->where('period_type', 'monthly')
                ->where('enabled', true)
                ->get()
                ->getRowArray();

            if ($budget) {
                $used = $this->monthlyUsage($db, $scope, $ref, $month);
                if ($budget['hard_limit'] > 0 && $used >= (float) $budget['hard_limit']) {
                    return new BudgetCheckResult(false, true, $scope, $ref, 'monthly', $used, (float) $budget['hard_limit']);
                }
            }
        }

        return new BudgetCheckResult(true, false);
    }

    /**
     * Record usage after a successful generation run.
     */
    public function recordUsage(array $usageData): void
    {
        $db = db_connect();
        $db->table('reach_ai_usage_ledger')->insert(array_merge($usageData, [
            'usage_date'    => date('Y-m-d'),
            'billing_month' => date('Y-m'),
            'created_at'    => date('Y-m-d H:i:s'),
        ]));
    }

    private function dailyUsage($db, string $scope, string $ref, string $date): float
    {
        return (float) $db->table('reach_ai_usage_ledger')
            ->selectSum('estimated_cost', 'total')
            ->where('usage_date', $date)
            ->get()
            ->getRowArray()['total'] ?? 0;
    }

    private function monthlyUsage($db, string $scope, string $ref, string $month): float
    {
        return (float) $db->table('reach_ai_usage_ledger')
            ->selectSum('estimated_cost', 'total')
            ->where('billing_month', $month)
            ->get()
            ->getRowArray()['total'] ?? 0;
    }
}
