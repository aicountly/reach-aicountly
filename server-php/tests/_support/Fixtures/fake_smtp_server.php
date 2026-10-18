<?php
declare(strict_types=1);

/**
 * A scripted one-connection SMTP server for SmtpClient tests (never used outside tests).
 *
 *   php fake_smtp_server.php <scenario.json> <port-file> <result.json>
 *
 * scenario: {
 *   "extensions": ["AUTH PLAIN LOGIN", "STARTTLS", "8BITMIME"],
 *   "cert": "/path/to/cert+key.pem",          // enables STARTTLS
 *   "auth_user": "u", "auth_pass": "p",        // expected credentials (535 otherwise)
 *   "reject_rcpt": ["bad@example.com"],        // 550 for these
 *   "data_code": 250,                           // reply after the final "."
 *   "stall_on": "DATA"                          // stop answering when this command arrives
 * }
 * result: {"commands": [...], "data": "<raw DATA payload, still dot-stuffed>", "tls": bool, "auth": "PLAIN|LOGIN|"}
 */

[$scenarioFile, $portFile, $resultFile] = array_slice($argv, 1, 3);
$s = json_decode((string) file_get_contents($scenarioFile), true) ?: [];

$ctx    = stream_context_create(['ssl' => isset($s['cert']) ? ['local_cert' => $s['cert'], 'verify_peer' => false, 'allow_self_signed' => true] : []]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents($portFile, substr($name, strrpos($name, ':') + 1));

$result = ['commands' => [], 'data' => '', 'tls' => false, 'auth' => ''];
$save   = static function () use (&$result, $resultFile): void {
    file_put_contents($resultFile, json_encode($result));
};

$conn = @stream_socket_accept($server, 15);
if ($conn === false) {
    $save();
    exit(0);
}
stream_set_timeout($conn, 15);
$say = static function (string $line) use ($conn): void {
    fwrite($conn, $line . "\r\n");
};
$ext = (array) ($s['extensions'] ?? ['AUTH PLAIN LOGIN']);

$say('220 fake.test ESMTP ready');
$authStep = null;
$user     = '';
while (($line = fgets($conn, 8192)) !== false) {
    $line = rtrim($line, "\r\n");
    $verb = strtoupper((string) strtok($line, ' '));

    if ($authStep === 'login_user') {
        $user     = (string) base64_decode($line);
        $authStep = 'login_pass';
        $result['commands'][] = '[login user]';
        $say('334 UGFzc3dvcmQ6');
        continue;
    }
    if ($authStep === 'login_pass') {
        $authStep = null;
        $result['commands'][] = '[login pass]';
        $okAuth = $user === ($s['auth_user'] ?? '') && base64_decode($line) === ($s['auth_pass'] ?? '');
        $result['auth'] = $okAuth ? 'LOGIN' : '';
        $say($okAuth ? '235 2.7.0 Authentication successful' : '535 5.7.8 Authentication credentials invalid');
        continue;
    }

    $result['commands'][] = str_starts_with($verb, 'AUTH') && str_contains(strtoupper($line), 'PLAIN') ? 'AUTH PLAIN [creds]' : $line;
    if (($s['stall_on'] ?? '') === $verb) {
        sleep(30);
        break;
    }

    switch ($verb) {
        case 'EHLO':
            $offer = $result['tls'] ? array_values(array_filter($ext, static fn ($e) => $e !== 'STARTTLS')) : $ext;
            $say('250' . ($offer === [] ? ' ' : '-') . 'fake.test greets you');
            foreach ($offer as $i => $e) {
                $say('250' . ($i === count($offer) - 1 ? ' ' : '-') . $e);
            }
            break;
        case 'STARTTLS':
            $say('220 2.0.0 Ready to start TLS');
            $result['tls'] = (bool) @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if (!$result['tls']) {
                $save();
                exit(0);
            }
            break;
        case 'AUTH':
            $parts = explode(' ', $line);
            if (strtoupper($parts[1] ?? '') === 'PLAIN') {
                $decoded = explode("\0", (string) base64_decode($parts[2] ?? ''));
                $okAuth  = ($decoded[1] ?? '') === ($s['auth_user'] ?? '') && ($decoded[2] ?? '') === ($s['auth_pass'] ?? '');
                $result['auth'] = $okAuth ? 'PLAIN' : '';
                $say($okAuth ? '235 2.7.0 Authentication successful' : '535 5.7.8 Authentication credentials invalid');
            } else {
                $authStep = 'login_user';
                $say('334 VXNlcm5hbWU6');
            }
            break;
        case 'MAIL':
            $say('250 2.1.0 Sender OK');
            break;
        case 'RCPT':
            preg_match('/<([^>]*)>/', $line, $m);
            $say(in_array($m[1] ?? '', (array) ($s['reject_rcpt'] ?? []), true) ? '550 5.1.1 No such user' : '250 2.1.5 Recipient OK');
            break;
        case 'DATA':
            $say('354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($d = fgets($conn, 8192)) !== false) {
                if ($d === ".\r\n") {
                    break;
                }
                $data .= $d;
            }
            $result['data'] = $data;
            $code = (int) ($s['data_code'] ?? 250);
            $say($code === 250 ? '250 2.0.0 Ok: queued as FAKE123' : $code . ' 5.7.1 Message rejected');
            break;
        case 'RSET':
            $say('250 2.0.0 Ok');
            break;
        case 'QUIT':
            $say('221 2.0.0 Bye');
            break 2;
        default:
            $say('502 5.5.2 Command not recognised');
    }
}
$save();
