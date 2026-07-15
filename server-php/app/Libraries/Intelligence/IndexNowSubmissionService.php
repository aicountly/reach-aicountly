<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence;

use App\Libraries\AuditLogger;
use App\Libraries\Intelligence\Connectors\IndexNowConnectorInterface;
use App\Models\Intelligence\IndexNowSubmissionModel;

class IndexNowSubmissionService
{
    private const ALLOWED_ENDPOINT_HOSTS = [
        'api.indexnow.org',
        'www.bing.com',
        'search.google.com',
    ];

    public function __construct(
        private IndexNowConnectorInterface $connector,
        private IndexNowSubmissionModel    $submissionModel,
        private AuditLogger                $auditLogger,
    ) {}

    public function submitUrl(int $tenantId, string $url, ?int $contentIdentityId = null): array
    {
        $endpoint       = getenv('INDEXNOW_ENDPOINT') ?: 'https://api.indexnow.org/indexnow';
        $this->assertEndpointAllowed($endpoint);

        $idempotencyKey = $this->makeIdempotencyKey($tenantId, $url, $endpoint);
        $existing       = $this->submissionModel->findByIdempotencyKey($tenantId, $idempotencyKey);

        if ($existing && in_array($existing['status'], ['submitted', 'pending'], true)) {
            return $existing;
        }

        $key = getenv('INDEXNOW_KEY_REFERENCE') ?: '';

        $submissionId = $this->submissionModel->insert([
            'tenant_id'             => $tenantId,
            'content_identity_id'   => $contentIdentityId,
            'url'                   => $url,
            'provider_endpoint'     => $endpoint,
            'idempotency_key'       => $idempotencyKey,
            'status'                => 'pending',
            'triggered_by'          => 'manual',
        ]);

        try {
            $result = $this->connector->submitUrl($url, $key);

            if ($result['success']) {
                $this->submissionModel->update($submissionId, [
                    'status'       => 'submitted',
                    'submitted_at' => date('Y-m-d H:i:s'),
                    'completed_at' => date('Y-m-d H:i:s'),
                    'attempt_count' => 1,
                ]);
                $this->recordAttempt($submissionId, 1, $result, true);
                $this->auditLogger->log(null, AuditLogger::INDEXNOW_SUBMITTED, 'indexnow_submission', $submissionId,
                    null, ['url' => $url], null, 'system');
            } else {
                $retryAt = $result['http_status'] === 429
                    ? date('Y-m-d H:i:s', time() + ($result['retry_after_secs'] ?? 300))
                    : null;

                $this->submissionModel->update($submissionId, [
                    'status'        => $retryAt ? 'retrying' : 'failed',
                    'attempt_count' => 1,
                    'next_retry_at' => $retryAt,
                ]);
                $this->recordAttempt($submissionId, 1, $result, false);
                $this->auditLogger->log(null, AuditLogger::INDEXNOW_FAILED, 'indexnow_submission', $submissionId,
                    null, null, ['url' => $url, 'error' => $result['error'] ?? 'unknown'], 'system');
            }
        } catch (\Throwable $e) {
            $this->submissionModel->update($submissionId, ['status' => 'failed']);
            $this->auditLogger->log(null, AuditLogger::INDEXNOW_FAILED, 'indexnow_submission', $submissionId,
                null, null, ['url' => $url, 'error' => $e->getMessage()], 'system');
        }

        return $this->submissionModel->find($submissionId);
    }

    public function submitBatch(int $tenantId, array $urls): array
    {
        $endpoint = getenv('INDEXNOW_ENDPOINT') ?: 'https://api.indexnow.org/indexnow';
        $this->assertEndpointAllowed($endpoint);

        $key     = getenv('INDEXNOW_KEY_REFERENCE') ?: '';
        $result  = $this->connector->submitBatch($urls, $key);
        return $result;
    }

    public function retryPending(): int
    {
        $pending = $this->submissionModel->getPendingRetries();
        $retried = 0;

        foreach ($pending as $submission) {
            try {
                $key    = getenv('INDEXNOW_KEY_REFERENCE') ?: '';
                $result = $this->connector->submitUrl($submission['url'], $key);
                $count  = (int) $submission['attempt_count'] + 1;

                if ($result['success']) {
                    $this->submissionModel->update($submission['id'], [
                        'status'        => 'submitted',
                        'submitted_at'  => date('Y-m-d H:i:s'),
                        'completed_at'  => date('Y-m-d H:i:s'),
                        'attempt_count' => $count,
                    ]);
                    $this->recordAttempt($submission['id'], $count, $result, true);
                    $this->auditLogger->log(null, AuditLogger::INDEXNOW_RETRIED, 'indexnow_submission', $submission['id'],
                        null, null, ['url' => $submission['url']], 'system');
                } else {
                    $maxAttempts = (int) $submission['max_attempts'];
                    $status      = $count >= $maxAttempts ? 'failed' : 'retrying';
                    $retryAt     = $status === 'retrying'
                        ? date('Y-m-d H:i:s', time() + 600)
                        : null;

                    $this->submissionModel->update($submission['id'], [
                        'status'        => $status,
                        'attempt_count' => $count,
                        'next_retry_at' => $retryAt,
                    ]);
                    $this->recordAttempt($submission['id'], $count, $result, false);
                }
                $retried++;
            } catch (\Throwable) {
                $this->submissionModel->update($submission['id'], ['status' => 'failed']);
            }
        }

        return $retried;
    }

    private function assertEndpointAllowed(string $endpoint): void
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!in_array($host, self::ALLOWED_ENDPOINT_HOSTS, true)) {
            throw new \RuntimeException("IndexNow endpoint host '{$host}' is not in the allowed list (SSRF prevention)");
        }
    }

    private function makeIdempotencyKey(int $tenantId, string $url, string $endpoint): string
    {
        return hash('sha256', "indexnow:{$tenantId}:{$endpoint}:{$url}");
    }

    private function recordAttempt(int $submissionId, int $attemptNum, array $result, bool $succeeded): void
    {
        $this->submissionModel->db->query(
            "INSERT INTO reach_indexnow_attempts
             (submission_id, attempt_number, http_status, provider_response, succeeded, attempted_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$submissionId, $attemptNum, $result['http_status'] ?? null, json_encode($result), $succeeded]
        );
    }
}
