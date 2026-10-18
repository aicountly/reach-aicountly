<?php

/**
 * A stand-in for aicountly.com's community receiver, for tests only:
 * `php -S 127.0.0.1:<port> fake_aicountly_member_questions.php` with
 * FAKE_SITE_TOKEN, FAKE_SITE_KEY and FAKE_SITE_DATA (a JSON file of questions)
 * in the environment.
 *
 * It checks the request exactly as the real receiver does (PublisherAuth +
 * HmacVerifier): bearer token, key id, timestamp skew, API version, body
 * checksum, and an HMAC-SHA256 over METHOD\nPATH\nTS\nNONCE\nBODY_SHA\nIDEMPOTENCY\nVERSION
 * where PATH is the request path without its query string. Only then does it
 * answer the member-questions listing.
 */
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    }
}
$fail = static function (int $status, string $code): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error_code' => $code, 'safe_error_message' => $code]);
    exit;
};

$path   = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
$body   = (string) file_get_contents('php://input');

if (($headers['authorization'] ?? '') !== 'Bearer ' . getenv('FAKE_SITE_TOKEN')) {
    $fail(401, 'authentication_error');
}
if (($headers['x-reach-key-id'] ?? '') !== 'reach-v1' || (int) ($headers['x-reach-api-version'] ?? 0) !== 1) {
    $fail(401, 'authentication_error');
}
$ts = (int) ($headers['x-reach-timestamp'] ?? 0);
if (abs(time() - $ts) > 300) {
    $fail(401, 'replay_rejected');
}
if (!hash_equals(hash('sha256', $body), (string) ($headers['x-reach-content-sha256'] ?? ''))) {
    $fail(409, 'checksum_mismatch');
}
$canonical = implode("\n", [$method, $path, (string) $ts, (string) ($headers['x-reach-nonce'] ?? ''),
    (string) ($headers['x-reach-content-sha256'] ?? ''), (string) ($headers['x-idempotency-key'] ?? ''), '1']);
if (!hash_equals(hash_hmac('sha256', $canonical, (string) getenv('FAKE_SITE_KEY')), (string) ($headers['x-reach-signature'] ?? ''))) {
    $fail(401, 'signature_error');
}

if ($method !== 'GET' || $path !== '/api/reach/v1/community/member-questions') {
    $fail(404, 'not_found');
}

$questions = json_decode((string) file_get_contents((string) getenv('FAKE_SITE_DATA')), true) ?: [];
header('Content-Type: application/json');
echo json_encode([
    'success'     => true,
    'since'       => $_GET['since'] ?? null,
    'questions'   => $questions,
    'next_cursor' => ['since' => gmdate('c'), 'after_id' => 0],
    'has_more'    => false,
]);
