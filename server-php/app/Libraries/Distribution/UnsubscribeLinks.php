<?php

declare(strict_types=1);

namespace App\Libraries\Distribution;

use App\Libraries\Settings\ReachConfig;

/**
 * Signed one-click unsubscribe links for campaign email.
 *
 *   {base}/api/v1/public/unsubscribe?t={tenant}&e={base64url(email)}&s={hmac}
 *
 * The HMAC key derives from REACH_SETTINGS_KEY (or JWT_SECRET), so links
 * cannot be forged to unsubscribe someone else. The endpoint adds the address
 * to the email suppression list, which every later audience snapshot honours.
 */
final class UnsubscribeLinks
{
    public static function isAvailable(): bool
    {
        return self::key() !== '' && self::baseUrl() !== '';
    }

    public static function baseUrl(): string
    {
        $base = '';
        try {
            $base = trim((string) ReachConfig::get('distribution.email.unsubscribe_base_url', ''));
        } catch (\Throwable) {
        }
        if ($base === '') {
            $base = trim((string) (env('REACH_APP_URL') ?: ''));
        }

        return rtrim($base, '/');
    }

    public static function url(int $tenantId, string $email): ?string
    {
        if (! self::isAvailable()) {
            return null;
        }
        $email = strtolower(trim($email));

        return self::baseUrl() . '/api/v1/public/unsubscribe?' . http_build_query([
            't' => $tenantId,
            'e' => rtrim(strtr(base64_encode($email), '+/', '-_'), '='),
            's' => self::sign($tenantId, $email),
        ]);
    }

    /** @return array{tenant_id:int, email:string}|null */
    public static function verify(string $tenant, string $encodedEmail, string $signature): ?array
    {
        if (self::key() === '' || ! ctype_digit($tenant)) {
            return null;
        }
        $decoded = base64_decode(strtr($encodedEmail, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $email = strtolower(trim($decoded));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        if (! hash_equals(self::sign((int) $tenant, $email), $signature)) {
            return null;
        }

        return ['tenant_id' => (int) $tenant, 'email' => $email];
    }

    /** Footer appended to every campaign email (unsubscribe link + postal address). */
    public static function footerHtml(string $unsubscribeUrl, string $postalAddress): string
    {
        $url  = htmlspecialchars($unsubscribeUrl, ENT_QUOTES);
        $addr = nl2br(htmlspecialchars($postalAddress, ENT_QUOTES));

        return '<hr style="border:none;border-top:1px solid #ddd;margin:24px 0 12px">'
            . '<p style="font-size:12px;color:#666;line-height:1.5">'
            . 'You are receiving this because you shared your contact details with AICOUNTLY. '
            . '<a href="' . $url . '">Unsubscribe</a> at any time.<br>' . $addr . '</p>';
    }

    public static function footerText(string $unsubscribeUrl, string $postalAddress): string
    {
        return "\n\n--\nUnsubscribe: {$unsubscribeUrl}\n{$postalAddress}\n";
    }

    private static function sign(int $tenantId, string $email): string
    {
        return hash_hmac('sha256', 'reach-unsubscribe-v1|' . $tenantId . '|' . $email, self::key());
    }

    private static function key(): string
    {
        $material = trim((string) (env('REACH_SETTINGS_KEY') ?: env('JWT_SECRET') ?: ''));

        return $material;
    }
}
