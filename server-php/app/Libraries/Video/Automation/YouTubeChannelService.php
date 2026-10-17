<?php

declare(strict_types=1);

namespace App\Libraries\Video\Automation;

use App\Libraries\AuditLogger;
use App\Libraries\Settings\ReachConfig;
use App\Libraries\Video\Automation\YouTube\YouTubeOAuth;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Connecting Reach to the AICOUNTLY YouTube channel (one channel per
 * deployment). An admin starts the OAuth flow from Reach → Video; Google
 * sends them back to the public callback, which checks the single-use state,
 * swaps the code for tokens, stores the refresh token encrypted
 * (ReachConfig secret video.youtube.refresh_token) and records which channel
 * it belongs to. Items parked waiting for a channel are released.
 */
class YouTubeChannelService
{
    private const STATE_TTL_SECS = 900;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $row = $this->row();
        $hasToken = YouTubeOAuth::hasRefreshToken();

        return [
            'connected'         => $hasToken && ($row['status'] ?? null) !== 'error',
            'status'            => ! $hasToken ? 'disconnected' : (string) ($row['status'] ?? 'connected'),
            'channel_id'        => $hasToken ? ($row['channel_id'] ?? null) : null,
            'channel_title'     => $hasToken ? ($row['channel_title'] ?? null) : null,
            'connected_at'      => $hasToken ? ($row['connected_at'] ?? null) : null,
            'last_error'        => $row['last_error'] ?? null,
            'client_configured' => YouTubeOAuth::clientConfigured(),
            'redirect_uri'      => YouTubeOAuth::redirectUri(),
            'privacy'           => (string) ReachConfig::get('video.youtube.privacy'),
        ];
    }

    /** The Google consent URL for an admin to open. */
    public function startConnect(?int $userId): string
    {
        $oauth = VideoProviders::oauth();
        if (! YouTubeOAuth::clientConfigured()) {
            throw VideoProviderException::notConfigured('Set the YouTube OAuth client ID and secret in Settings → AI & Automation → Video first.');
        }
        $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->query("DELETE FROM reach_video_oauth_states WHERE expires_at < NOW() - INTERVAL '1 day'");
        $this->db->query(
            'INSERT INTO reach_video_oauth_states (nonce, user_id, created_at, expires_at) VALUES (?, ?, NOW(), NOW() + make_interval(secs => ?))',
            [$nonce, $userId, self::STATE_TTL_SECS],
        );
        return $oauth->consentUrl($nonce);
    }

    /**
     * Finish the flow. Returns null on success, else a short reason safe to
     * show in the redirect.
     */
    public function completeConnect(string $state, string $code, string $error = ''): ?string
    {
        $userId = $this->consumeState($state);
        if ($userId === false) {
            return 'invalid_state';
        }
        if ($error !== '') {
            AuditLogger::record('video.youtube.connect_denied', ['error' => mb_substr($error, 0, 100)], $userId ?: null);
            return 'consent_denied';
        }
        if ($code === '') {
            return 'missing_code';
        }

        try {
            $oauth  = VideoProviders::oauth();
            $tokens = $oauth->exchangeCode($code);
            ReachConfig::set('video.youtube.refresh_token', $tokens['refresh_token'], $userId ?: null);
            ReachConfig::flush();
            $channel = VideoProviders::youtube()->channel();
        } catch (\Throwable $e) {
            $this->saveRow(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
            log_message('error', '[YouTubeConnect] ' . $e->getMessage());
            return 'connect_failed';
        }

        $this->saveRow([
            'status'               => 'connected',
            'channel_id'           => $channel['id'],
            'channel_title'        => mb_substr($channel['title'], 0, 255),
            'scopes'               => $tokens['scope'] !== '' ? $tokens['scope'] : implode(' ', YouTubeOAuth::SCOPES),
            'connected_by_user_id' => $userId ?: null,
            'connected_at'         => gmdate('Y-m-d H:i:s'),
            'last_error'           => null,
        ]);
        AuditLogger::record('video.youtube.connected', ['channel_id' => $channel['id'], 'channel_title' => $channel['title']], $userId ?: null);

        (new VideoPipelineSweeper())->releaseBlocked(['youtube_upload', 'youtube_extras']);
        return null;
    }

    public function disconnect(?int $userId): void
    {
        $token = ReachConfig::secret('video.youtube.refresh_token');
        if ($token !== null) {
            try {
                VideoProviders::oauth()->revoke($token);
            } catch (\Throwable $e) {
                log_message('warning', '[YouTubeDisconnect] revoke failed: ' . $e->getMessage());
            }
        }
        ReachConfig::set('video.youtube.refresh_token', '', $userId);
        ReachConfig::flush();
        $this->saveRow(['status' => 'disconnected', 'channel_id' => null, 'channel_title' => null, 'connected_at' => null, 'last_error' => null]);
        AuditLogger::record('video.youtube.disconnected', [], $userId);
    }

    /** Record that Google refused our token (revoked / expired). */
    public function markError(string $message): void
    {
        $this->saveRow(['status' => 'error', 'last_error' => mb_substr($message, 0, 1000)]);
    }

    /** @return int|false|null the user who started the flow (null = unknown), false when invalid */
    private function consumeState(string $state): int|false|null
    {
        if ($state === '' || strlen($state) > 64 || ! preg_match('/^[A-Za-z0-9_\-]+$/', $state)) {
            return false;
        }
        // Single use, atomically: only the first callback with this state wins.
        $row = $this->db->query(
            'UPDATE reach_video_oauth_states SET used_at = NOW() WHERE nonce = ? AND used_at IS NULL AND expires_at > NOW() RETURNING user_id',
            [$state],
        )->getRowArray();
        if (! $row) {
            return false;
        }
        return $row['user_id'] !== null ? (int) $row['user_id'] : null;
    }

    /** @return array<string, mixed>|null */
    private function row(): ?array
    {
        try {
            return $this->db->table('reach_video_youtube_channel')->where('id', 1)->get()->getRowArray() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $fields */
    private function saveRow(array $fields): void
    {
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($this->row() === null) {
            $this->db->table('reach_video_youtube_channel')->insert(['id' => 1] + $fields);
        } else {
            $this->db->table('reach_video_youtube_channel')->where('id', 1)->update($fields);
        }
    }
}
