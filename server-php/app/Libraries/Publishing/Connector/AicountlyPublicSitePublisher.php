<?php

namespace App\Libraries\Publishing\Connector;

/**
 * Phase 4 — Production publisher that communicates with aicountly.com.
 *
 * Secrets come from environment only and are never logged.
 * All requests are HMAC-signed per the publishing API contract.
 */
class AicountlyPublicSitePublisher implements PublicSitePublisherInterface
{
    private string $baseUrl;
    private string $serviceToken;
    private string $signingKey;
    private string $keyId;
    private int $timeout;
    private int $apiVersion;
    private HmacSigner $signer;
    private PublishingErrorClassifier $classifier;

    public function __construct()
    {
        $this->baseUrl       = rtrim((string) ($_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL'] ?? ''), '/');
        $this->serviceToken  = (string) ($_ENV['AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN'] ?? '');
        $this->signingKey    = (string) ($_ENV['AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'] ?? '');
        $this->keyId         = (string) ($_ENV['AICOUNTLY_PUBLIC_SITE_KEY_ID'] ?? 'reach-v1');
        $this->timeout       = (int) ($_ENV['AICOUNTLY_PUBLIC_SITE_TIMEOUT'] ?? 15);
        $this->apiVersion    = (int) ($_ENV['AICOUNTLY_PUBLIC_SITE_API_VERSION'] ?? 1);
        $this->signer        = new HmacSigner();
        $this->classifier    = new PublishingErrorClassifier();
    }

    private function isConfigured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->serviceToken) && !empty($this->signingKey);
    }

    public function createDraft(array $envelope): array
    {
        return $this->post('/reach/v1/content/drafts', $envelope);
    }

    public function updateDraft(int $publicContentId, array $envelope): array
    {
        return $this->put("/reach/v1/content/drafts/{$publicContentId}", $envelope);
    }

    public function publish(int $publicContentId, array $envelope): array
    {
        return $this->post("/reach/v1/content/{$publicContentId}/publish", $envelope);
    }

    public function schedule(int $publicContentId, array $envelope, string $scheduledAt): array
    {
        $envelope['scheduled_at'] = $scheduledAt;
        return $this->post("/reach/v1/content/{$publicContentId}/schedule", $envelope);
    }

    public function unpublish(int $publicContentId, string $reason): array
    {
        return $this->post("/reach/v1/content/{$publicContentId}/unpublish", ['reason' => $reason]);
    }

    public function restore(int $publicContentId, array $envelope): array
    {
        return $this->post("/reach/v1/content/{$publicContentId}/restore", $envelope);
    }

    public function getStatus(int $publicContentId): array
    {
        return $this->get("/reach/v1/content/{$publicContentId}");
    }

    public function getVerification(int $publicContentId): array
    {
        return $this->get("/reach/v1/content/{$publicContentId}/verification");
    }

    public function triggerVerification(int $publicContentId): array
    {
        return $this->post("/reach/v1/content/{$publicContentId}/verify", []);
    }

    public function healthCheck(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->get('/reach/v1/health', requireAuth: false);
            return ($response['ok'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, $body);
    }

    private function get(string $path, bool $requireAuth = true): array
    {
        return $this->request('GET', $path, [], $requireAuth);
    }

    private function request(string $method, string $path, array $body = [], bool $requireAuth = true): array
    {
        if ($requireAuth && !$this->isConfigured()) {
            return $this->errorResult('configuration_error');
        }

        $rawBody      = empty($body) ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestId    = ($body['request_id'] ?? '') ?: $this->generateRequestId();
        $idempotency  = $body['idempotency_key'] ?? $requestId;

        $headers = $requireAuth
            ? $this->signer->buildAuthHeaders(
                $method,
                $path,
                $rawBody,
                $idempotency,
                $requestId,
                $this->serviceToken,
                $this->signingKey,
                $this->keyId,
                $this->apiVersion
            )
            : ['Content-Type' => 'application/json'];

        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $curlHeaders = array_map(
            fn($k, $v) => "{$k}: {$v}",
            array_keys($headers),
            array_values($headers)
        );

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if (in_array($method, ['POST', 'PUT'], true) && !empty($rawBody)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        try {
            $responseBody = curl_exec($ch);
            $httpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            curl_close($ch);
            $category = $this->classifier->classifyException($e);
            return $this->errorResult($category);
        }

        if ($curlError || $responseBody === false) {
            return $this->errorResult('network_error');
        }

        $decoded = json_decode($responseBody, true);

        if ($httpStatus >= 200 && $httpStatus < 300) {
            if (!is_array($decoded)) {
                return $this->errorResult('server_error', $httpStatus);
            }
            return array_merge(['success' => true], $decoded);
        }

        $category = $this->classifier->classifyHttpStatus($httpStatus);
        return $this->errorResult($category, $httpStatus);
    }

    private function errorResult(string $category, int $httpStatus = 0): array
    {
        return [
            'success'           => false,
            'error_category'    => $category,
            'safe_error_message'=> $this->classifier->safeMessage($category, $httpStatus),
        ];
    }

    private function generateRequestId(): string
    {
        $prefix = (string) ($_ENV['REACH_REQUEST_ID_PREFIX'] ?? 'reach');
        return $prefix . '-' . bin2hex(random_bytes(6));
    }
}
