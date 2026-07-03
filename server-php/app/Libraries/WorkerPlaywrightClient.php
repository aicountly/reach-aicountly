<?php

namespace App\Libraries;

use App\Models\WorkerHealthSnapshotModel;

/**
 * worker.apis.aicountly.com client — Playwright UI / screenshot / review jobs only.
 *
 * Reach never runs marketing bot logic in the worker. This client is used
 * by the Marketing Bot when it wants a visual verification (e.g. review a
 * scheduled landing-page mock, screenshot a published blog post for the
 * report timeline). Graceful degradation: missing env / token results in a
 * skipped record — never a fatal error.
 */
class WorkerPlaywrightClient
{
    private string $baseUrl;
    private string $token;
    private WorkerHealthSnapshotModel $snapshots;

    public function __construct()
    {
        $this->baseUrl   = rtrim((string) env('WORKER_BASE_URL', ''), '/');
        $this->token     = (string) env('WORKER_API_TOKEN', '');
        $this->snapshots = new WorkerHealthSnapshotModel();
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /** @return array{ok:bool, status:int, data:?array, error:?string, latency_ms:int} */
    public function health(): array
    {
        return $this->call('GET', 'v1/health');
    }

    /** @return array{ok:bool, status:int, data:?array, error:?string, latency_ms:int} */
    public function screenshot(array $body): array
    {
        return $this->call('POST', 'v1/screenshot', $body);
    }

    public function review(array $body): array
    {
        return $this->call('POST', 'v1/review', $body);
    }

    public function runJob(array $body): array
    {
        return $this->call('POST', 'v1/runs', $body);
    }

    /**
     * Record a health snapshot for the admin worker-status page.
     */
    public function pingAndRecord(): array
    {
        $res = $this->health();
        try {
            $this->snapshots->insert([
                'checked_at'    => date('Y-m-d H:i:s'),
                'ok'            => $res['ok'],
                'http_status'   => $res['status'],
                'latency_ms'    => $res['latency_ms'],
                'response'      => $res['data'] !== null ? json_encode($res['data'], JSON_UNESCAPED_SLASHES) : null,
                'error_message' => $res['error'],
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Worker snapshot write failed: ' . $e->getMessage());
        }
        return $res;
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok'         => false,
                'status'     => 0,
                'data'       => null,
                'error'      => 'WORKER_BASE_URL / WORKER_API_TOKEN not configured',
                'latency_ms' => 0,
            ];
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch  = curl_init($url);
        if ($ch === false) {
            return [
                'ok'         => false,
                'status'     => 0,
                'data'       => null,
                'error'      => 'curl_init failed',
                'latency_ms' => 0,
            ];
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);

        $start   = microtime(true);
        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        $latency = (int) round((microtime(true) - $start) * 1000);
        curl_close($ch);

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $ok      = $raw !== false && $status >= 200 && $status < 300;

        return [
            'ok'         => $ok,
            'status'     => $status,
            'data'       => is_array($decoded) ? $decoded : null,
            'error'      => $ok ? null : ($err !== '' ? $err : (is_string($raw) ? substr($raw, 0, 500) : 'unknown error')),
            'latency_ms' => $latency,
        ];
    }
}
