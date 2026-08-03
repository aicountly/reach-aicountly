<?php

namespace App\Commands;

use App\Libraries\Publishing\Connector\HmacSigner;
use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:pub-auth-check`
 *
 * Safe (no secret values) check that Reach ↔ public-site publish credentials
 * are present, health responds, and a signed auth probe reports the public
 * site's safe error (e.g. Invalid bearer credential).
 */
class ReachPubAuthCheck extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:pub-auth-check';
    protected $description = 'Check public-site publisher env + health + signed auth probe (no secrets printed).';
    protected $usage       = 'reach:pub-auth-check';

    public function run(array $params): int
    {
        $baseUrl = rtrim((string) ($_ENV['AICOUNTLY_PUBLIC_SITE_BASE_URL'] ?? getenv('AICOUNTLY_PUBLIC_SITE_BASE_URL') ?: ''), '/');
        $token   = (string) ($_ENV['AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN'] ?? getenv('AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN') ?: '');
        $signing = (string) ($_ENV['AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'] ?? getenv('AICOUNTLY_PUBLIC_SITE_SIGNING_KEY') ?: '');
        $keyId   = (string) ($_ENV['AICOUNTLY_PUBLIC_SITE_KEY_ID'] ?? getenv('AICOUNTLY_PUBLIC_SITE_KEY_ID') ?: 'reach-v1');

        $publisher = PublicSitePublisherFactory::make();
        $healthOk  = false;
        $healthErr = null;
        try {
            $healthOk = $publisher->healthCheck();
        } catch (\Throwable $e) {
            $healthErr = $e->getMessage();
        }

        $probe = $this->signedAuthProbe($baseUrl, $token, $signing, $keyId);

        $report = [
            'event' => 'pub_auth_check',
            'ts'    => gmdate('c'),
            'env'   => [
                'AICOUNTLY_PUBLIC_SITE_BASE_URL'      => $baseUrl !== '' ? $baseUrl : null,
                'AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN' => $token !== '',
                'AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'   => $signing !== '',
                'AICOUNTLY_PUBLIC_SITE_KEY_ID'        => $keyId,
                'token_fingerprint'                  => $this->fingerprint($token),
                'signing_key_fingerprint'            => $this->fingerprint($signing),
                'token_length'                       => strlen($token),
                'signing_key_length'                 => strlen($signing),
                'REACH_PUB_MOCK'                     => filter_var(
                    $_ENV['REACH_PUB_MOCK'] ?? getenv('REACH_PUB_MOCK') ?: 'false',
                    FILTER_VALIDATE_BOOLEAN
                ),
                'publisher_class'                    => $publisher::class,
            ],
            'health' => [
                'ok'    => $healthOk,
                'path'  => '/reach/v1/health',
                'error' => $healthErr,
            ],
            'auth_probe' => $probe,
            'match_on_public_site' => [
                'AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN'    => 'same value as Reach (compare token_fingerprint)',
                'AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'      => 'same value as Reach (compare signing_key_fingerprint)',
                'AICOUNTLY_PUBLIC_SITE_KEY_ID'           => 'same value as Reach (default reach-v1)',
                'AICOUNTLY_PUBLIC_SITE_MAX_SKEW_SECONDS' => 'optional on public site (default 300)',
            ],
            'next' => [],
        ];

        if ($baseUrl === '' || $token === '' || $signing === '') {
            $report['next'][] = 'Set AICOUNTLY_PUBLIC_SITE_BASE_URL, _SERVICE_TOKEN, and _SIGNING_KEY in Reach api/.env';
            $report['next'][] = 'Use the same AICOUNTLY_PUBLIC_SITE_* names and values on aicountly.com.';
        } elseif (! $healthOk) {
            $report['next'][] = 'Public /reach/v1/health failed — confirm publisher is deployed on aicountly.com and BASE_URL is correct.';
            $report['next'][] = 'curl -sS ' . $baseUrl . '/reach/v1/health';
        } elseif (($probe['http_status'] ?? 0) === 401) {
            $report['next'][] = 'Auth probe got 401 — bearer/signing values do not match what aicountly.com validates.';
            $report['next'][] = 'On aicountly.com, print the same fingerprints from its .env and make them identical to Reach.';
            $report['next'][] = 'php -r \'$e=parse_ini_file(".env",false,INI_SCANNER_RAW); foreach(["AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN","AICOUNTLY_PUBLIC_SITE_SIGNING_KEY"] as $k){ $v=trim((string)($e[$k]??""),"\"\' "); echo $k," ",substr(hash("sha256",$v),0,12)," len=",strlen($v),"\n"; }\'';
            $report['next'][] = 'If the public blog receiver still reads legacy REACH_SERVICE_TOKEN, either update it to AICOUNTLY_PUBLIC_SITE_* or set REACH_* to the same values as aliases.';
        } elseif (in_array((int) ($probe['http_status'] ?? 0), [400, 422], true)
            || in_array((string) ($probe['error_code'] ?? ''), ['validation_error', 'payload_invalid'], true)
        ) {
            $report['next'][] = 'Auth accepted (got validation/payload error, not 401). Re-run: php spark reach:blog-advance --dispatch && php spark reach:work --queue blog,publishing,community,default --limit 40';
        } else {
            $report['next'][] = 'Inspect auth_probe.http_status / error_code. After tokens match: php spark reach:blog-advance --dispatch && php spark reach:work --queue blog,publishing,community,default --limit 40';
        }

        CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $authOk = in_array((int) ($probe['http_status'] ?? 0), [200, 201, 400, 422], true)
            || in_array((string) ($probe['error_code'] ?? ''), ['validation_error', 'payload_invalid'], true);

        return ($baseUrl !== '' && $token !== '' && $signing !== '' && $healthOk && $authOk) ? 0 : 2;
    }

    private function fingerprint(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return substr(hash('sha256', $value), 0, 12);
    }

    /**
     * Signed POST against create-draft with an intentionally incomplete body.
     * Auth OK → usually validation/payload error. Auth bad → 401.
     *
     * @return array<string, mixed>
     */
    private function signedAuthProbe(string $baseUrl, string $token, string $signing, string $keyId): array
    {
        if ($baseUrl === '' || $token === '' || $signing === '') {
            return ['ok' => false, 'skipped' => true, 'reason' => 'missing_credentials'];
        }

        $path = '/reach/v1/content/drafts';
        $body = json_encode([
            'api_version'     => 1,
            'operation'       => 'create_draft',
            'idempotency_key' => 'auth-probe-' . gmdate('YmdHis'),
            'request_id'      => 'auth-probe-' . bin2hex(random_bytes(4)),
        ], JSON_UNESCAPED_SLASHES);

        $headers = (new HmacSigner())->buildAuthHeaders(
            'POST',
            $path,
            $body,
            'auth-probe-' . gmdate('YmdHis'),
            'auth-probe-' . bin2hex(random_bytes(4)),
            $token,
            $signing,
            $keyId,
            1
        );

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = $k . ': ' . $v;
        }

        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw        = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $bodySnippet = is_string($raw) ? mb_substr(trim($raw), 0, 400) : null;

        return [
            'ok'                 => $httpStatus > 0 && $httpStatus !== 401,
            'path'               => $path,
            'http_status'        => $httpStatus,
            'curl_error'         => $curlError !== '' ? $curlError : null,
            'error_code'         => is_array($decoded) ? ($decoded['error_code'] ?? null) : null,
            'safe_error_message' => is_array($decoded) ? ($decoded['safe_error_message'] ?? null) : null,
            'body_snippet'       => $bodySnippet,
        ];
    }
}
