<?php

declare(strict_types=1);

namespace App\Libraries\Gateways;

/**
 * Transactional email via Itwalk / Infobip API (multipart or JSON).
 *
 * Env: EMAIL_API_KEY, EMAIL_API_URL, EMAIL_API_FORMAT, SENDER_EMAIL, SENDER_NAME
 */
final class Mailer
{
    private const DEFAULT_API_URL = 'https://e.api.itwalk.in/email/1/send';

    private static ?string $lastError = null;
    private static ?string $lastSendMode = null;
    private static ?string $lastSendUrl = null;
    private static ?string $lastMessageId = null;

    public static function isConfigured(): bool
    {
        return trim((string) (getenv('EMAIL_API_KEY') ?: $_ENV['EMAIL_API_KEY'] ?? '')) !== '';
    }

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    public static function getLastSendMode(): ?string
    {
        return self::$lastSendMode;
    }

    public static function getLastSendUrl(): ?string
    {
        return self::$lastSendUrl;
    }

    public static function getLastMessageId(): ?string
    {
        return self::$lastMessageId;
    }

    /**
     * @param string|array<int,array{email:string,name?:string}> $to
     */
    public static function send(string|array $to, string $subject, string $body, ?string $plainText = null): bool
    {
        self::$lastError = null;
        self::$lastSendMode = null;
        self::$lastSendUrl = null;
        self::$lastMessageId = null;

        $apiKey = trim((string) (getenv('EMAIL_API_KEY') ?: $_ENV['EMAIL_API_KEY'] ?? ''));
        if ($apiKey === '') {
            self::$lastError = 'EMAIL_API_KEY not configured.';
            log_message('error', 'Mailer::send failed — ' . self::$lastError);

            return false;
        }

        $recipient = self::normalizeRecipient($to);
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Invalid recipient email.';
            log_message('error', 'Mailer::send failed — ' . self::$lastError);

            return false;
        }

        if (!function_exists('curl_init')) {
            self::$lastError = 'PHP curl extension is not enabled.';
            log_message('error', 'Mailer::send failed — ' . self::$lastError);

            return false;
        }

        $senderEmail = trim((string) (getenv('SENDER_EMAIL') ?: $_ENV['SENDER_EMAIL'] ?? 'support@aicountly.com'));
        $senderName  = trim((string) (getenv('SENDER_NAME') ?: $_ENV['SENDER_NAME'] ?? 'AICOUNTLY Reach'));
        $replyTo     = trim((string) (getenv('REPLY_TO_EMAIL') ?: $_ENV['REPLY_TO_EMAIL'] ?? $senderEmail));
        $apiUrl      = trim((string) (getenv('EMAIL_API_URL') ?: $_ENV['EMAIL_API_URL'] ?? self::DEFAULT_API_URL));

        if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            self::$lastError = 'Invalid SENDER_EMAIL.';
            log_message('error', 'Mailer::send failed — ' . self::$lastError);

            return false;
        }

        $from = $senderName !== '' ? "{$senderName} <{$senderEmail}>" : $senderEmail;
        $text = $plainText ?? self::htmlToPlainText($body);
        $format = strtolower(trim((string) (getenv('EMAIL_API_FORMAT') ?: $_ENV['EMAIL_API_FORMAT'] ?? 'auto')));

        $payload = [
            'from'    => $from,
            'to'      => $recipient,
            'replyTo' => $replyTo !== '' ? $replyTo : $senderEmail,
            'subject' => $subject,
            'html'    => $body,
            'text'    => $text,
            'track'   => false,
        ];

        $attempts = self::buildAttempts($apiUrl, $format);
        $lastResponse = '';
        $lastHttp     = 0;

        foreach ($attempts as $attempt) {
            $result = self::executeRequest($apiKey, $attempt['url'], $attempt['mode'], $payload);
            $lastResponse = $result['response'];
            $lastHttp     = $result['http_code'];

            if ($result['curl_error'] !== '') {
                self::$lastError = 'curl: ' . $result['curl_error'];
                continue;
            }

            $parsed = self::parseItwalkResponse($lastResponse, $lastHttp);
            if ($parsed['ok']) {
                self::$lastError = null;
                self::$lastSendMode = $attempt['mode'];
                self::$lastSendUrl = $attempt['url'];
                self::$lastMessageId = $parsed['message_id'] !== '' ? $parsed['message_id'] : null;

                return true;
            }

            self::$lastError = $parsed['reason'];
        }

        log_message('error', sprintf(
            'Mailer::send failed http=%d to=%s reason=%s response=%s',
            $lastHttp,
            $recipient,
            self::$lastError ?? 'unknown',
            substr($lastResponse, 0, 800),
        ));

        return false;
    }

    /** @return list<array{url:string,mode:string}> */
    private static function buildAttempts(string $apiUrl, string $format): array
    {
        $usesV1 = preg_match('#/email/1/send$#', $apiUrl) === 1;

        if ($format === 'auto' && $usesV1) {
            return [['url' => $apiUrl, 'mode' => 'multipart']];
        }

        $jsonUrl = self::jsonApiUrl($apiUrl);

        return match ($format) {
            'json'      => [['url' => $jsonUrl, 'mode' => 'json']],
            'multipart' => [['url' => $apiUrl, 'mode' => 'multipart']],
            default     => [
                ['url' => $jsonUrl, 'mode' => 'json'],
                ['url' => $apiUrl, 'mode' => 'multipart'],
            ],
        };
    }

    private static function jsonApiUrl(string $apiUrl): string
    {
        if (preg_match('#/email/1/send$#', $apiUrl) === 1) {
            return preg_replace('#/email/1/send$#', '/email/3/send', $apiUrl) ?? $apiUrl;
        }

        return $apiUrl;
    }

    /** @param array{from:string,to:string,replyTo:string,subject:string,html:string,text:string,track:bool} $payload */
    private static function executeRequest(string $apiKey, string $url, string $mode, array $payload): array
    {
        $headers = [
            'Accept: application/json',
            "Authorization: App {$apiKey}",
        ];

        if ($mode === 'json') {
            $body = json_encode([
                'from'    => $payload['from'],
                'to'      => $payload['to'],
                'replyTo' => $payload['replyTo'],
                'subject' => $payload['subject'],
                'html'    => $payload['html'],
                'text'    => $payload['text'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($body === false) {
                return ['response' => '', 'http_code' => 0, 'curl_error' => 'json_encode failed'];
            }

            $headers[] = 'Content-Type: application/json';
            $postFields = $body;
        } else {
            $postFields = [
                'from'    => $payload['from'],
                'to'      => $payload['to'],
                'replyTo' => $payload['replyTo'],
                'subject' => $payload['subject'],
                'html'    => $payload['html'],
                'text'    => $payload['text'],
                'track'   => 'false',
            ];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['response' => '', 'http_code' => 0, 'curl_error' => 'curl_init failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return [
            'response'   => is_string($response) ? $response : '',
            'http_code'  => $httpCode,
            'curl_error' => $curlErr,
        ];
    }

    /** @return array{ok: bool, reason: string, message_id: string} */
    private static function parseItwalkResponse(string $response, int $httpCode): array
    {
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'reason' => 'HTTP ' . $httpCode, 'message_id' => ''];
        }

        if ($response === '') {
            return ['ok' => false, 'reason' => 'Empty response body from email gateway', 'message_id' => ''];
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            $trimmed = trim($response);
            if ($trimmed !== '' && !str_contains(strtolower($trimmed), 'error') && !str_contains(strtolower($trimmed), 'fail')) {
                return ['ok' => true, 'reason' => 'non_json_response', 'message_id' => ''];
            }

            return ['ok' => false, 'reason' => 'Unexpected non-JSON response from email gateway', 'message_id' => ''];
        }

        if (isset($json['requestError']) && is_array($json['requestError'])) {
            $text = (string) ($json['requestError']['serviceException']['text']
                ?? $json['requestError']['message']
                ?? 'request rejected');

            return ['ok' => false, 'reason' => $text, 'message_id' => ''];
        }

        if (!empty($json['error']) || !empty($json['errors'])) {
            $text = is_string($json['error'] ?? null)
                ? (string) $json['error']
                : json_encode($json['errors'] ?? $json['error']);

            return ['ok' => false, 'reason' => $text !== '' ? $text : 'gateway error', 'message_id' => ''];
        }

        $messageId = trim((string) ($json['bulkId'] ?? $json['messageId'] ?? ''));
        $messages  = $json['messages'] ?? [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            if ($messageId === '') {
                $messageId = (string) ($message['messageId'] ?? $message['id'] ?? '');
            }

            $status = is_array($message['status'] ?? null) ? $message['status'] : [];
            $groupName = strtoupper((string) ($status['groupName'] ?? ''));
            $statusName = strtoupper((string) ($status['name'] ?? ''));
            $description = (string) ($status['description'] ?? '');

            if (
                str_contains($groupName, 'REJECT')
                || str_contains($statusName, 'REJECT')
                || str_contains($description, 'rejected')
            ) {
                return [
                    'ok'         => false,
                    'reason'     => $description !== '' ? $description : ($statusName !== '' ? $statusName : 'message rejected'),
                    'message_id' => $messageId,
                ];
            }
        }

        if ($messageId !== '' || $messages !== []) {
            return ['ok' => true, 'reason' => 'accepted', 'message_id' => $messageId];
        }

        return ['ok' => false, 'reason' => 'Gateway returned no message id (send may not have been queued)', 'message_id' => ''];
    }

    private static function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /** @param string|array<int,array{email:string,name?:string}> $to */
    private static function normalizeRecipient(string|array $to): string
    {
        if (is_string($to)) {
            return trim($to);
        }

        $emails = [];
        foreach ($to as $entry) {
            $email = trim((string) ($entry['email'] ?? ''));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return implode(',', $emails);
    }
}
