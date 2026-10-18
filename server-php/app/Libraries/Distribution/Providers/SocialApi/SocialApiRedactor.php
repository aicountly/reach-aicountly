<?php

declare(strict_types=1);

namespace App\Libraries\Distribution\Providers\SocialApi;

/**
 * Scrubs social-api.ai requests/responses before Reach stores or logs them.
 *
 *   - the configured API key (and any `sapi_key_…` string, `Bearer …` value)
 *   - values of credential-looking keys (api_key, token, secret, password,
 *     authorization, signature, credential)
 *   - the query string of every URL that carries a signature / token
 *     (presigned upload URLs are bearer credentials until they expire)
 *
 * Deliberately not SecretRedactor: its "long opaque string" heuristic would
 * blank the post / media / account ids that make a stored response useful.
 */
final class SocialApiRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    private const KEY_PATTERN = '/(^|[_\-.])(api[_\-]?key|apikey|key|token|access[_\-]?token|refresh[_\-]?token|secret|client[_\-]?secret|password|authorization|signature|credentials?)$/i';

    /** Query parameters that make a URL a credential. */
    private const SIGNED_QUERY = '/[?&](x-amz-[a-z\-]+|signature|sig|token|access_token|key|expires|x-goog-[a-z\-]+|se|sp|sv|st)=/i';

    /** @var list<string> */
    private array $secrets;

    /** @param list<string|null> $secrets */
    public function __construct(array $secrets = [])
    {
        $list = [];
        foreach ($secrets as $s) {
            $s = trim((string) $s);
            if (strlen($s) >= 6) {
                $list[] = $s;
                $list[] = rawurlencode($s);
            }
        }
        $list = array_values(array_unique($list));
        usort($list, static fn ($a, $b) => strlen($b) <=> strlen($a));
        $this->secrets = $list;
    }

    public function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 12) {
            return self::PLACEHOLDER;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && preg_match(self::KEY_PATTERN, $k) && ! in_array(strtolower($k), ['idempotency_key'], true)) {
                    $out[$k] = ($v === null || $v === '') ? $v : self::PLACEHOLDER;
                    continue;
                }
                $out[$k] = $this->value($v, $depth + 1);
            }
            return $out;
        }
        return is_string($value) ? $this->string($value) : $value;
    }

    public function string(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::PLACEHOLDER, $text);
        }
        $text = preg_replace('/sapi_key_[A-Za-z0-9_\-]+/', self::PLACEHOLDER, $text) ?? $text;
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{6,}/i', '$1 ' . self::PLACEHOLDER, $text) ?? $text;

        // Signed URLs: keep scheme/host/path (useful for diagnosis), drop the query.
        return preg_replace_callback('#https?://[^\s"\'<>]+#i', static function (array $m): string {
            $url = $m[0];
            $q   = strpos($url, '?');
            if ($q === false || ! preg_match(self::SIGNED_QUERY, substr($url, $q))) {
                return $url;
            }
            return substr($url, 0, $q) . '?' . self::PLACEHOLDER;
        }, $text) ?? $text;
    }

    /** JSON for a DB column / log line, redacted. */
    public function json(mixed $value): string
    {
        return (string) json_encode($this->value($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
