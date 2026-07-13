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
    private const HALF_OPEN_PROBE_TTL = 30;  // seconds the half-open window stays open

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

        if (!$row['is_circuit_open']) {
            return false;
        }

        // Check if cooldown has elapsed → half-open
        $openedAt = strtotime($row['circuit_opened_at'] ?? '1970-01-01');
        if ((time() - $openedAt) >= self::COOLDOWN_SECONDS) {
            return false; // Allow a probe attempt
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
             SET consecutive_failures = 0, is_circuit_open = FALSE,
                 circuit_opened_at = NULL, last_success_at = NOW(), updated_at = NOW()
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

        $newCount = ((int) $row['consecutive_failures']) + 1;
        $shouldOpen = $newCount >= self::FAILURE_THRESHOLD;

        $db->query(
            "UPDATE reach_ai_provider_health
             SET consecutive_failures = ?,
                 is_circuit_open = ?,
                 circuit_opened_at = CASE WHEN ? THEN NOW() ELSE circuit_opened_at END,
                 last_failure_at = NOW(),
                 last_error_category = ?,
                 updated_at = NOW()
             WHERE provider_id = (SELECT id FROM reach_ai_providers WHERE provider_key = ? LIMIT 1)",
            [$newCount, $shouldOpen ? 'true' : 'false', $shouldOpen ? 'true' : 'false', $errorCategory, $providerKey]
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
        if (!$row['is_circuit_open']) {
            return 'closed';
        }
        $openedAt = strtotime($row['circuit_opened_at'] ?? '1970-01-01');
        if ((time() - $openedAt) >= self::COOLDOWN_SECONDS) {
            return 'half_open';
        }
        return 'open';
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
