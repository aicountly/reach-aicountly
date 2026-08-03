<?php

namespace App\Commands;

use App\Libraries\Publishing\Connector\PublicSitePublisherFactory;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * `php spark reach:pub-auth-check`
 *
 * Safe (no secret values) check that Reach ↔ public-site publish credentials
 * are present and that the public receiver health endpoint responds.
 */
class ReachPubAuthCheck extends BaseCommand
{
    protected $group       = 'Reach';
    protected $name        = 'reach:pub-auth-check';
    protected $description = 'Check public-site publisher env + /reach/v1/health (no secrets printed).';
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

        $report = [
            'event' => 'pub_auth_check',
            'ts'    => gmdate('c'),
            'env'   => [
                'AICOUNTLY_PUBLIC_SITE_BASE_URL'      => $baseUrl !== '' ? $baseUrl : null,
                'AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN' => $token !== '',
                'AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'   => $signing !== '',
                'AICOUNTLY_PUBLIC_SITE_KEY_ID'        => $keyId,
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
            'match_on_public_site' => [
                'AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN'     => 'same value as Reach api/.env',
                'AICOUNTLY_PUBLIC_SITE_SIGNING_KEY'       => 'same value as Reach api/.env',
                'AICOUNTLY_PUBLIC_SITE_KEY_ID'            => 'same value as Reach api/.env (default reach-v1)',
                'AICOUNTLY_PUBLIC_SITE_MAX_SKEW_SECONDS'  => 'optional on public site (default 300)',
            ],
            'next' => [],
        ];

        if ($baseUrl === '' || $token === '' || $signing === '') {
            $report['next'][] = 'Set AICOUNTLY_PUBLIC_SITE_BASE_URL, _SERVICE_TOKEN, and _SIGNING_KEY in Reach api/.env';
            $report['next'][] = 'Use the same AICOUNTLY_PUBLIC_SITE_* names and values on aicountly.com (not REACH_SERVICE_TOKEN / REACH_SIGNING_KEY).';
        } elseif (! $healthOk) {
            $report['next'][] = 'Public /reach/v1/health failed — confirm publisher is deployed on aicountly.com and BASE_URL is correct.';
            $report['next'][] = 'curl -sS ' . $baseUrl . '/reach/v1/health';
        } else {
            $report['next'][] = 'Health OK. If deployments still return authentication_error, AICOUNTLY_PUBLIC_SITE_SERVICE_TOKEN / _SIGNING_KEY / _KEY_ID do not match between Reach and aicountly.com.';
            $report['next'][] = 'After fixing tokens: php spark reach:blog-advance --dispatch && php spark reach:work --queue blog,publishing,community,default --limit 40';
        }

        CLI::write(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($baseUrl !== '' && $token !== '' && $signing !== '' && $healthOk) ? 0 : 2;
    }
}
