<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers;

use App\Libraries\Email\EmailDispatcher;

/**
 * Campaign email through AICOUNTLY's own mail server — the central Email delivery API
 * (email-aicountly docs/DELIVERY_API.md), category `marketing` (the dedicated marketing IP pool).
 *
 *   POST {AICOUNTLY_EMAIL_API_URL}/v1/service/deliveries
 *   X-Service-Key: {AICOUNTLY_EMAIL_SERVICE_KEY}            (Reach's key in Email's SERVICE_KEYS)
 *   Idempotency-Key: reach:<attempt key>                    (stable per recipient-message)
 *   {"category":"marketing","to":[one address],"subject","body_text","body_html","from_name",
 *    "reply_to","template_ref","unsubscribe_url":"https://… (RFC 8058 one-click)"}
 *
 * Selected only by EMAIL_PROVIDER=aicountly_email (ChannelProviderFactory); every other value keeps
 * the Console-registry path (GatewayEmailSender). The From address is the Email API's
 * (`reach@news.aicountly.io`) — a product cannot choose another.
 *
 * Answers (contract):
 *   202 / 200 replayed       accepted; the delivery id is the provider message id (a replay
 *                            returns the same id — nothing was sent twice)
 *   429 / 503 (and no answer, 500/502/504)
 *                            retried here with the SAME Idempotency-Key (Retry-After honoured,
 *                            capped); still refused → RuntimeException code 429, which makes
 *                            ChannelDispatchService put the recipient back and retry the batch
 *                            later — again with the same key, because the key derives from the
 *                            dispatch + recipient, never from the attempt
 *   422 / 409 / other 4xx    RuntimeException with that code — never retried unchanged
 *   401 / 403                ProviderNotConfiguredException — configuration (key, DELIVERY_PRODUCTS),
 *                            the dispatch stops instead of failing every recipient one by one
 *
 * Delivery outcomes (relayed, bounced, complained, unsubscribed, …) arrive later on the signed
 * webhook (AicountlyEmailEvents, POST v1/inbound/aicountly-email/events). The service key never
 * appears in an exception, a receipt or a log line.
 */
class AicountlyEmailSender implements EmailSenderInterface
{
    public const PROVIDER          = 'aicountly_email';
    public const CATEGORY          = 'marketing';
    public const KEY_PREFIX        = 'reach:';
    public const DEFAULT_TIMEOUT   = 15;
    public const DEFAULT_RETRIES   = 2;
    public const MAX_RETRY_WAIT    = 10;

    /** HTTP statuses retried with the same Idempotency-Key (0 = no answer / timeout). */
    private const RETRYABLE = [0, 429, 500, 502, 503, 504];

    /** @var callable(string $method, string $url, array<string,string> $headers, ?string $body, int $timeout): array{status:int, body:string, headers:array<string,string>, error:string} */
    private $http;

    /** @var callable(int $seconds): void */
    private $sleep;

    /**
     * @param (callable(string, string, array<string,string>, ?string, int): array{status:int, body:string, headers:array<string,string>, error:string})|null $http
     * @param (callable(int): void)|null $sleep
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $serviceKey,
        ?callable $http = null,
        ?callable $sleep = null,
        private readonly int $maxRetries = self::DEFAULT_RETRIES,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly string $fromName = '',
        private readonly string $replyTo = '',
    ) {
        $this->http  = $http ?? [self::class, 'httpRequest'];
        $this->sleep = $sleep ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    public static function fromEnv(): self
    {
        $retries = self::env('AICOUNTLY_EMAIL_MAX_RETRIES');
        $timeout = (int) self::env('AICOUNTLY_EMAIL_TIMEOUT');

        return new self(
            self::env('AICOUNTLY_EMAIL_API_URL'),
            self::env('AICOUNTLY_EMAIL_SERVICE_KEY'),
            null,
            null,
            $retries === '' ? self::DEFAULT_RETRIES : max(0, min(5, (int) $retries)),
            $timeout > 0 ? min($timeout, 60) : self::DEFAULT_TIMEOUT,
            self::env('AICOUNTLY_EMAIL_FROM_NAME') ?: self::env('SENDER_NAME') ?: 'Aicountly Reach',
            self::env('AICOUNTLY_EMAIL_REPLY_TO') ?: self::env('REPLY_TO_EMAIL') ?: self::env('SENDER_EMAIL'),
        );
    }

    // ── EmailSenderInterface ────────────────────────────────────────────────

    public function send(ChannelMessage $message): ProviderReceipt
    {
        $endpoint = self::deliveriesUrl($this->baseUrl);
        if ($endpoint === null || $this->serviceKey === '') {
            throw new ProviderNotConfiguredException(
                'AICOUNTLY Email is selected (EMAIL_PROVIDER=aicountly_email) but AICOUNTLY_EMAIL_API_URL (https) and AICOUNTLY_EMAIL_SERVICE_KEY are not both set. Nothing was sent.'
            );
        }

        $key     = self::idempotencyKey($message->idempotencyKey);
        $payload = $this->payload($message);
        $body    = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
            'X-Service-Key'   => $this->serviceKey,
            'Idempotency-Key' => $key,
        ];

        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                $res = ($this->http)('POST', $endpoint, $headers, $body, $this->timeout);
            } catch (\Throwable $e) {
                $res = ['status' => 0, 'body' => '', 'headers' => [], 'error' => $e->getMessage()];
            }
            $status = (int) ($res['status'] ?? 0);

            if ($status === 200 || $status === 201 || $status === 202) {
                return $this->receipt($res, $key, $attempts);
            }
            if (! in_array($status, self::RETRYABLE, true)) {
                $this->refuse($status, $res);
            }
            if ($attempts > $this->maxRetries) {
                throw new \RuntimeException(
                    'AICOUNTLY Email ' . self::describe($status, $res) . ' after ' . $attempts . ' attempt(s); retry later with the same Idempotency-Key (' . $key . ').',
                    429,
                );
            }
            ($this->sleep)(self::retryDelay($res, $attempts));
        }
    }

    public function sendBatch(array $messages): array
    {
        return array_map(fn ($m) => $this->send($m), $messages);
    }

    /** Live status from the mail server, first (only) recipient; normalised to Reach attempt statuses. */
    public function getStatus(string $providerMessageId): ProviderStatus
    {
        $base = self::deliveriesUrl($this->baseUrl);
        if ($base === null || $this->serviceKey === '' || $providerMessageId === '') {
            return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'unknown');
        }
        try {
            $res = ($this->http)('GET', $base . '/' . rawurlencode($providerMessageId), ['Accept' => 'application/json', 'X-Service-Key' => $this->serviceKey], null, $this->timeout);
        } catch (\Throwable) {
            $res = ['status' => 0, 'body' => ''];
        }
        $data = json_decode((string) ($res['body'] ?? ''), true);
        $data = is_array($data) && is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) ? $data : []);
        $rcpt = is_array($data['recipients'] ?? null) && is_array($data['recipients'][0] ?? null) ? $data['recipients'][0] : [];
        $raw  = (string) ($rcpt['status'] ?? $data['status'] ?? '');
        if ((int) ($res['status'] ?? 0) !== 200 || $raw === '') {
            return new ProviderStatus(providerMessageId: $providerMessageId, normalisedStatus: 'unknown');
        }

        return new ProviderStatus(
            providerMessageId: $providerMessageId,
            normalisedStatus:  self::attemptStatusFor($raw) ?? 'accepted',
            failureClass:      $raw === 'bounced' ? self::bounceFailureClass((string) ($rcpt['bounce_class'] ?? '')) : null,
            raw:               ['status' => $raw, 'dsn' => $rcpt['dsn'] ?? null, 'bounce_class' => $rcpt['bounce_class'] ?? null],
        );
    }

    public function getCapabilities(): array
    {
        return [
            'batch_size' => 1,
            'rate_limit' => 100,
            'features'   => ['unsubscribe_header', 'idempotency', 'delivery_webhook'],
            'provider'   => self::PROVIDER,
            'category'   => self::CATEGORY,
        ];
    }

    public function isEnabled(): bool
    {
        return self::deliveriesUrl($this->baseUrl) !== null && $this->serviceKey !== '';
    }

    /** Signature + freshness only (dedupe on event_id is AicountlyEmailEvents::ingest). */
    public function verifyCallback(array $headers, string $rawBody): bool
    {
        $h = array_change_key_case($headers, CASE_LOWER);

        return \App\Libraries\Distribution\AicountlyEmailEvents::verifySignature(
            self::env('AICOUNTLY_EMAIL_WEBHOOK_SECRET'),
            (string) ($h['x-aicountly-timestamp'] ?? ''),
            (string) ($h['x-aicountly-signature'] ?? ''),
            $rawBody,
        ) === null;
    }

    public function providerName(): string
    {
        return self::PROVIDER;
    }

    // ── contract helpers (public for the webhook and tests) ─────────────────

    /**
     * The Idempotency-Key for a Reach message: "reach:" + the dispatch's per-recipient key
     * (`d<dispatch>:r<recipient>`, `test:…`). Same message → same key, every retry. A key that would
     * not fit the contract (8–120 printable characters) becomes "reach:sha256:<hex>" — still stable.
     */
    public static function idempotencyKey(string $messageKey): string
    {
        $key = self::KEY_PREFIX . $messageKey;
        if (preg_match('/\A[\x21-\x7E]{8,120}\z/', $key) === 1) {
            return $key;
        }

        return self::KEY_PREFIX . 'sha256:' . hash('sha256', $messageKey);
    }

    /** The Reach attempt key behind an Idempotency-Key we sent, or null when it is not ours / hashed. */
    public static function messageKeyFrom(string $idempotencyKey): ?string
    {
        if (! str_starts_with($idempotencyKey, self::KEY_PREFIX) || str_starts_with($idempotencyKey, self::KEY_PREFIX . 'sha256:')) {
            return null;
        }
        $key = substr($idempotencyKey, strlen(self::KEY_PREFIX));

        return $key !== '' ? $key : null;
    }

    /**
     * `{base}/v1/service/deliveries` for a base URL given with or without `/v1`
     * (https://email.apis.aicountly.com, https://email.gh.aicountly.com/api, …/api/v1). https only.
     */
    public static function deliveriesUrl(string $baseUrl): ?string
    {
        $base = rtrim(trim($baseUrl), '/');
        if ($base === '' || preg_match('#\Ahttps://[^\s/?\#]+(/[^\s?\#]*)?\z#i', $base) !== 1) {
            return null;
        }
        if (str_ends_with(strtolower($base), '/v1')) {
            $base = substr($base, 0, -3);
        }

        return $base . '/v1/service/deliveries';
    }

    /**
     * Mail-server delivery status / webhook event → reach_campaign_delivery_attempts.status,
     * or null when the event changes nothing on the attempt (queued, deferred, uncertain, failover).
     */
    public static function attemptStatusFor(string $event): ?string
    {
        return match ($event) {
            'pending', 'queued', 'deferred', 'uncertain', 'failover' => null,
            'submitted'    => 'sent',
            'relayed'      => 'delivered',
            'bounced'      => 'bounced',
            'complained'   => 'complained',
            'unsubscribed' => 'unsubscribed',
            'suppressed'   => 'suppressed',
            'expired', 'cancelled' => 'failed',
            default        => null,
        };
    }

    /** hard (and unknown) bounces are permanent; mailbox_full, soft, policy, … are transient. */
    public static function bounceFailureClass(string $bounceClass): string
    {
        return in_array($bounceClass, ['hard', ''], true) ? 'permanent' : 'transient';
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function payload(ChannelMessage $message): array
    {
        $to = strtolower(trim($message->recipientAddress));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false || str_contains($to, ',')) {
            throw new \RuntimeException('Invalid email recipient address.', 422);
        }
        $subject = trim((string) ($message->metadata['subject'] ?? ''));
        if ($subject === '') {
            throw new \RuntimeException('Email subject is empty.', 422);
        }
        $unsubscribe = trim((string) ($message->metadata['unsubscribe_url'] ?? ''));
        if (preg_match('#\Ahttps://\S+\z#i', $unsubscribe) !== 1) {
            throw new \RuntimeException(
                'AICOUNTLY Email marketing mail needs an https one-click unsubscribe_url (set REACH_APP_URL or distribution.email.unsubscribe_base_url to an https URL). Nothing was sent.',
                422,
            );
        }

        $html = $message->content;
        $text = is_string($message->metadata['body_text'] ?? null) && trim($message->metadata['body_text']) !== ''
            ? $message->metadata['body_text']
            : EmailDispatcher::htmlToText($html);

        $payload = [
            'category'        => self::CATEGORY,
            'to'              => [$to],
            'subject'         => $subject,
            'body_text'       => $text,
            'unsubscribe_url' => $unsubscribe,
            'template_ref'    => is_string($message->metadata['template_ref'] ?? null) && $message->metadata['template_ref'] !== ''
                ? $message->metadata['template_ref']
                : 'reach.campaign.email',
        ];
        if (trim($html) !== '') {
            $payload['body_html'] = $html;
        }
        if ($this->fromName !== '') {
            $payload['from_name'] = $this->fromName;
        }
        if ($this->replyTo !== '' && filter_var($this->replyTo, FILTER_VALIDATE_EMAIL) !== false) {
            $payload['reply_to'] = $this->replyTo;
        }

        return $payload;
    }

    /** @param array{status:int, body:string} $res */
    private function receipt(array $res, string $key, int $attempts): ProviderReceipt
    {
        $decoded = json_decode((string) ($res['body'] ?? ''), true);
        $data    = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $id      = is_scalar($data['id'] ?? null) ? trim((string) $data['id']) : '';
        if ($id === '') {
            // Accepted but unreadable: do not invent an id. 502 = failed/transient; a later retry
            // of this recipient reuses the key and gets the original id back as a replay.
            throw new \RuntimeException('AICOUNTLY Email accepted the request (HTTP ' . (int) $res['status'] . ') but returned no delivery id.', 502);
        }
        $replayed = is_array($decoded) && ! empty($decoded['replayed']);

        return new ProviderReceipt(
            providerMessageId: $id,
            status:            'accepted',
            acceptedAt:        new \DateTimeImmutable(),
            rawResponse:       json_encode([
                'provider'        => self::PROVIDER,
                'delivery_id'     => $id,
                'status'          => (string) ($data['status'] ?? ''),
                'category'        => (string) ($data['category'] ?? self::CATEGORY),
                'replayed'        => $replayed,
                'idempotency_key' => $key,
                'attempts'        => $attempts,
            ], JSON_UNESCAPED_SLASHES) ?: null,
            meta: ['idempotency_key' => $key, 'replayed' => $replayed, 'attempts' => $attempts],
        );
    }

    /**
     * Non-retryable answer → exception. Never returns.
     *
     * @param array{status:int, body:string} $res
     */
    private function refuse(int $status, array $res): never
    {
        $what = 'AICOUNTLY Email ' . self::describe($status, $res);
        if ($status === 401 || $status === 403) {
            throw new ProviderNotConfiguredException($what . ' — check AICOUNTLY_EMAIL_SERVICE_KEY and that Email lists reach:marketing in DELIVERY_PRODUCTS. Nothing was sent.', 503);
        }
        if ($status === 409) {
            throw new \RuntimeException($what . ' — the Idempotency-Key was already used for a different message. Not retried.', 409);
        }

        throw new \RuntimeException($what . ' — not retried.', $status >= 400 && $status < 500 ? $status : 502);
    }

    /** "refused (HTTP 422 invalid_input: field unsubscribe_url — …)" from the error envelope; never the request. */
    private static function describe(int $status, array $res): string
    {
        if ($status === 0) {
            $err = trim((string) ($res['error'] ?? ''));

            return 'did not answer' . ($err !== '' ? ' (' . self::clip($err, 200) . ')' : '');
        }
        $decoded = json_decode((string) ($res['body'] ?? ''), true);
        $error   = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $parts   = [];
        foreach (['code', 'message'] as $k) {
            if (is_scalar($error[$k] ?? null) && (string) $error[$k] !== '') {
                $parts[] = (string) $error[$k];
            }
        }
        if (is_array($error['details'] ?? null) && is_scalar($error['details']['field'] ?? null)) {
            $parts[] = 'field ' . $error['details']['field'];
        }

        return 'refused (HTTP ' . $status . ($parts !== [] ? ': ' . self::clip(implode(' — ', $parts), 300) : '') . ')';
    }

    /** Retry-After (seconds or HTTP date), capped; else 1 s, 2 s, 4 s … */
    private static function retryDelay(array $res, int $attempt): int
    {
        $headers = array_change_key_case(is_array($res['headers'] ?? null) ? $res['headers'] : [], CASE_LOWER);
        $after   = trim((string) ($headers['retry-after'] ?? ''));
        if ($after !== '') {
            $seconds = ctype_digit($after) ? (int) $after : (($t = strtotime($after)) !== false ? $t - time() : -1);
            if ($seconds >= 0) {
                return min($seconds, self::MAX_RETRY_WAIT);
            }
        }

        return min(2 ** ($attempt - 1), self::MAX_RETRY_WAIT);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, headers:array<string,string>, error:string}
     */
    public static function httpRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (! function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl extension missing'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl_init failed'];
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return ['status' => $raw === false ? 0 : $status, 'body' => is_string($raw) ? $raw : '', 'headers' => $received, 'error' => $err];
    }

    private static function clip(string $text, int $max): string
    {
        return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
    }

    private static function env(string $key): string
    {
        $v = function_exists('env') ? env($key, '') : ($_ENV[$key] ?? getenv($key));
        if (! is_string($v) || trim($v) === '') {
            $v = getenv($key);
        }

        return is_string($v) ? trim($v) : '';
    }
}
