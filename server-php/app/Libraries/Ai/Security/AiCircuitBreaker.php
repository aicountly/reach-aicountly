<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Security;

/**
 * Phase 3 — AI Provider Circuit Breaker.
 *
 * Implements a simple open/half-open/closed circuit breaker backed by
 * the `reach_ai_provider_health` table. When a provider exceeds the
 * failure threshold within a rolling window, the circuit opens and all
 * subsequent generation calls to that provider are short-circuited until
 * the cooldown period expires (half-open probe).
 *
 * State transitions:
 *   closed  → open      : consecutive failures >= threshold
 *   open    → half-open : cooldown period elapsed
 *   half-open → closed  : probe succeeds
 *   half-open → open    : probe fails
 */
class AiCircuitBreaker
{
    private const FAILURE_THRESHOLD   = 5;   // consecutive failures to trip
    private const COOLDOWN_SECONDS    = 120; // seconds before half-open probe

    /**
     * Check if the circuit is open (should block the call).
     */
    public function isOpen(string $providerKey): bool
    {
        $db = \Config\Database::connect();
        $row = $this->getRow($db, $providerKey);
        if (!$row) {
            return false;
        }

        $state = $row['circuit_state'] ?? 'closed';
        if ($state !== 'open') {
            return false;
        }

        // Check if cooldown has elapsed → half-open (allow probe)
        $cooldownUntil = $row['cooldown_until'] ?? null;
        if ($cooldownUntil !== null && strtotime((string) $cooldownUntil) <= time()) {
            $this->setState($db, $providerKey, 'half_open', (int) ($row['failure_count'] ?? 0));
            return false;
        }

        // Fallback: if cooldown_until missing, use last_failure_at + COOLDOWN
        if ($cooldownUntil === null) {
            $failedAt = strtotime($row['last_failure_at'] ?? '1970-01-01');
            if ((time() - $failedAt) >= self::COOLDOWN_SECONDS) {
                $this->setState($db, $providerKey, 'half_open', (int) ($row['failure_count'] ?? 0));
                return false;
            }
        }

        return true;
    }

    /**
     * Record a successful call; close or keep closed.
     */
    public function recordSuccess(string $providerKey): void
    {
        $db = \Config\Database::connect();
        $db->query(
            "UPDATE reach_ai_provider_health
             SET failure_count = 0,
                 circuit_state = 'closed',
                 cooldown_until = NULL,
                 health_status = 'healthy',
                 checked_at = NOW(),
                 error_message = NULL,
                 updated_at = NOW()
             WHERE provider_id = (SELECT id FROM reach_ai_providers WHERE provider_key = ? LIMIT 1)",
            [$providerKey]
        );
    }

    /**
     * Record a failure; trip the circuit if threshold reached.
     */
    public function recordFailure(string $providerKey, string $errorCategory = 'unknown'): void
    {
        $db = \Config\Database::connect();

        $row = $this->getRow($db, $providerKey);
        if (!$row) {
            return;
        }

        $newCount = ((int) ($row['failure_count'] ?? 0)) + 1;
        $shouldOpen = $newCount >= self::FAILURE_THRESHOLD;
        $state = $shouldOpen ? 'open' : ($row['circuit_state'] ?? 'closed');
        $cooldownSql = $shouldOpen
            ? 'NOW() + INTERVAL \'' . self::COOLDOWN_SECONDS . ' seconds\''
            : 'cooldown_until';

        $db->query(
            "UPDATE reach_ai_provider_health
             SET failure_count = ?,
                 circuit_state = ?,
                 cooldown_until = {$cooldownSql},
                 last_failure_at = NOW(),
                 health_status = 'unhealthy',
                 error_message = ?,
                 checked_at = NOW(),
                 updated_at = NOW()
             WHERE provider_id = (SELECT id FROM reach_ai_providers WHERE provider_key = ? LIMIT 1)",
            [
                $newCount,
                $state,
                $errorCategory,
                $providerKey,
            ]
        );
    }

    /**
     * Returns the circuit state for a provider key.
     */
    public function getState(string $providerKey): string
    {
        $db  = \Config\Database::connect();
        $row = $this->getRow($db, $providerKey);
        if (!$row) {
            return 'closed';
        }

        $state = $row['circuit_state'] ?? 'closed';
        if ($state !== 'open') {
            return $state;
        }

        $cooldownUntil = $row['cooldown_until'] ?? null;
        if ($cooldownUntil !== null && strtotime((string) $cooldownUntil) <= time()) {
            return 'half_open';
        }

        if ($cooldownUntil === null) {
            $failedAt = strtotime($row['last_failure_at'] ?? '1970-01-01');
            if ((time() - $failedAt) >= self::COOLDOWN_SECONDS) {
                return 'half_open';
            }
        }

        return 'open';
    }

    private function setState(
        \CodeIgniter\Database\ConnectionInterface $db,
        string $providerKey,
        string $state,
        int $failureCount
    ): void {
        $db->query(
            "UPDATE reach_ai_provider_health
             SET circuit_state = ?, failure_count = ?, updated_at = NOW()
             WHERE provider_id = (SELECT id FROM reach_ai_providers WHERE provider_key = ? LIMIT 1)",
            [$state, $failureCount, $providerKey]
        );
    }

    private function getRow(\CodeIgniter\Database\ConnectionInterface $db, string $providerKey): ?array
    {
        return $db->query(
            "SELECT h.* FROM reach_ai_provider_health h
             JOIN reach_ai_providers p ON p.id = h.provider_id
             WHERE p.provider_key = ? LIMIT 1",
            [$providerKey]
        )->getRowArray() ?: null;
    }
}
