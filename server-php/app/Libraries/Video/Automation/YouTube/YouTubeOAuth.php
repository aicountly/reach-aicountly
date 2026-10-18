<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation\YouTube;

use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\Http\CurlTransport;
use App\Libraries\Video\Automation\Http\HttpTransport;
use App\Libraries\Video\Automation\VideoProviderException;

/**
 * Google OAuth 2.0 web-server flow for the one YouTube channel Reach uploads to.
 *
 *   consentUrl()   → admin is sent to Google (offline access, forced consent so
 *                    a refresh token is always issued)
 *   exchangeCode() → the callback swaps the code for tokens
 *   accessToken()  → each pipeline step mints a short-lived access token from
 *                    the stored (encrypted) refresh token
 *
 * Redirect URI: {REACH_APP_URL}/api/v1/public/video/youtube/oauth-callback —
 * it must be registered exactly on the OAuth client in Google Cloud.
 */
final class YouTubeOAuth
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',    // videos.insert, thumbnails.set
        'https://www.googleapis.com/auth/youtube.force-ssl', // captions.insert, channels.list
    ];

    private const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private HttpTransport $http;
    private ?string $accessToken = null;
    private int $accessTokenExpires = 0;
    private ?string $grantedScope = null;

    public function __construct(?HttpTransport $http = null)
    {
        $this->http = $http ?? new CurlTransport();
    }

    public static function redirectUri(): string
    {
        $base = rtrim(trim((string) (env('REACH_APP_URL') ?: 'https://reach.aicountly.org')), '/');
        return $base . '/api/v1/public/video/youtube/oauth-callback';
    }

    public static function clientConfigured(): bool
    {
        return trim((string) ReachConfig::get('video.youtube.client_id')) !== '' && ReachConfig::secret('video.youtube.client_secret') !== null;
    }

    public static function hasRefreshToken(): bool
    {
        return ReachConfig::secret('video.youtube.refresh_token') !== null;
    }

    public function consentUrl(string $state): string
    {
        $this->assertClient();
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => trim((string) ReachConfig::get('video.youtube.client_id')),
            'redirect_uri'           => self::redirectUri(),
            'response_type'          => 'code',
            'scope'                  => implode(' ', self::SCOPES),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{refresh_token: string, access_token: string, expires_in: int, scope: string}
     */
    public function exchangeCode(string $code): array
    {
        $this->assertClient();
        $res = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => trim((string) ReachConfig::get('video.youtube.client_id')),
            'client_secret' => (string) ReachConfig::secret('video.youtube.client_secret'),
            'redirect_uri'  => self::redirectUri(),
        ]), ['timeout' => 30]);
        if (! $res->ok()) {
            throw VideoProviderException::fromResponse('Google OAuth', 'code exchange', $res);
        }
        $json    = $res->json();
        $refresh = (string) ($json['refresh_token'] ?? '');
        $access  = (string) ($json['access_token'] ?? '');
        if ($refresh === '' || $access === '') {
            throw new VideoProviderException('Google did not return a refresh token. Remove Reach’s access at myaccount.google.com/permissions and connect again.', VideoProviderException::PERMANENT);
        }
        $granted = (string) ($json['scope'] ?? '');
        foreach (self::SCOPES as $scope) {
            if ($granted !== '' && ! str_contains($granted, $scope)) {
                throw new VideoProviderException('Google did not grant the YouTube permissions Reach needs (' . $scope . '). Connect again and tick every box on the consent screen.', VideoProviderException::PERMANENT);
            }
        }
        $this->accessToken        = $access;
        $this->accessTokenExpires = time() + max(60, (int) ($json['expires_in'] ?? 3600)) - 60;
        $this->grantedScope       = $granted !== '' ? $granted : null;

        return ['refresh_token' => $refresh, 'access_token' => $access, 'expires_in' => (int) ($json['expires_in'] ?? 3600), 'scope' => $granted];
    }

    /** A valid access token, refreshed from the stored refresh token when needed. */
    public function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpires) {
            return $this->accessToken;
        }
        $refresh = ReachConfig::secret('video.youtube.refresh_token');
        if ($refresh === null) {
            throw VideoProviderException::notConfigured('No YouTube channel is connected — an admin must click “Connect YouTube channel” in Reach → Video.');
        }
        $this->assertClient();
        $res = $this->http->request('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => trim((string) ReachConfig::get('video.youtube.client_id')),
            'client_secret' => (string) ReachConfig::secret('video.youtube.client_secret'),
        ]), ['timeout' => 30]);
        if (! $res->ok()) {
            $err = (string) ($res->json()['error'] ?? '');
            if ($res->status === 400 && in_array($err, ['invalid_grant', 'unauthorized_client', 'invalid_client'], true)) {
                throw new VideoProviderException('YouTube access was revoked or expired (' . $err . ') — reconnect the channel in Reach → Video.', VideoProviderException::AUTH);
            }
            throw VideoProviderException::fromResponse('Google OAuth', 'token refresh', $res);
        }
        $json = $res->json();
        $this->accessToken        = (string) ($json['access_token'] ?? '');
        $this->accessTokenExpires = time() + max(60, (int) ($json['expires_in'] ?? 3600)) - 60;
        $this->grantedScope       = is_string($json['scope'] ?? null) && $json['scope'] !== '' ? $json['scope'] : null;
        if ($this->accessToken === '') {
            throw new VideoProviderException('Google returned no access token.', VideoProviderException::TRANSIENT);
        }
        return $this->accessToken;
    }

    /**
     * Scopes Google reported with the last token it issued (space-separated),
     * or null when it did not say.
     */
    public function grantedScopes(): ?string
    {
        return $this->grantedScope;
    }

    /** Best-effort revoke at Google; true when Google confirmed. */
    public function revoke(string $token): bool
    {
        $res = $this->http->request('POST', self::REVOKE_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['token' => $token]), ['timeout' => 20]);
        return $res->ok();
    }

    private function assertClient(): void
    {
        if (! self::clientConfigured()) {
            throw VideoProviderException::notConfigured('YouTube OAuth client is not configured: set video.youtube.client_id and video.youtube.client_secret in Settings → AI & Automation → Video.');
        }
    }
}
