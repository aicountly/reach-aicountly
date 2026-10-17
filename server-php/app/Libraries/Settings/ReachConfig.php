<?php

namespace App\Libraries\Settings;

use App\Models\SettingModel;
use RuntimeException;

/**
 * Operator-editable configuration for the automation pipelines (AI keys, the
 * vetting model per content type, image model, volumes, report recipients,
 * third-party credentials). Values live in `reach_settings`; secrets are
 * encrypted at rest. Every key has a registry entry so the Settings → AI &
 * Automation screen knows what to offer, and an optional `.env` fallback so a
 * deployment configured the old way keeps working until someone saves a value.
 *
 * Read with get() / secret(); write with set(). Never read these keys straight
 * from SettingModel — the secret ones come back as ciphertext.
 */
final class ReachConfig
{
    private const ENC_PREFIX = 'v1:';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * key => [group, label, type (string|secret|int|bool|email_list|model), env?, default?, help?]
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // ── AI providers ────────────────────────────────────────────────
            'ai.gemini.api_key' => ['group' => 'AI providers', 'label' => 'Gemini API key', 'type' => 'secret', 'env' => 'AI_GEMINI_API_KEY',
                'help' => 'Google AI Studio key. Used for vetting, insights and AI-visibility checks.'],
            'ai.openai.api_key' => ['group' => 'AI providers', 'label' => 'OpenAI API key', 'type' => 'secret', 'env' => 'AI_OPENAI_API_KEY',
                'help' => 'Used for image generation (DALL·E) and AI-visibility checks.'],
            'ai.perplexity.api_key' => ['group' => 'AI providers', 'label' => 'Perplexity API key', 'type' => 'secret', 'env' => 'AI_PERPLEXITY_API_KEY',
                'help' => 'Optional. AI-visibility checks.'],

            // ── Vetting (the final decision maker; no human review) ─────────
            'ai.vetting.model.default' => ['group' => 'AI vetting', 'label' => 'Default vetting model', 'type' => 'model', 'env' => 'AI_GEMINI_DEFAULT_MODEL', 'default' => 'gemini-2.5-flash',
                'help' => 'Gemini model that vets and finalises content. Its decision is final.'],
            'ai.vetting.model.community' => ['group' => 'AI vetting', 'label' => 'Community Q&A vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.blog' => ['group' => 'AI vetting', 'label' => 'Blog vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.knowledge_base' => ['group' => 'AI vetting', 'label' => 'Knowledge Base vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.release_note' => ['group' => 'AI vetting', 'label' => 'Release notes vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.vetting.model.workflow_guide' => ['group' => 'AI vetting', 'label' => 'Workflow guide vetting model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],
            'ai.insights.model' => ['group' => 'AI vetting', 'label' => 'AI Insights model', 'type' => 'model', 'help' => 'Blank = default vetting model.'],

            // ── Images ──────────────────────────────────────────────────────
            'ai.image.model' => ['group' => 'Images', 'label' => 'Image model (OpenAI)', 'type' => 'string', 'env' => 'AI_OPENAI_IMAGE_MODEL', 'default' => 'dall-e-3',
                'help' => 'e.g. dall-e-3 or gpt-image-1.'],
            'ai.image.size' => ['group' => 'Images', 'label' => 'Image size', 'type' => 'string', 'default' => '1792x1024',
                'help' => 'dall-e-3: 1792x1024 / 1024x1024; gpt-image-1: 1536x1024.'],

            // ── Automation volumes & switches ───────────────────────────────
            'automation.community.enabled' => ['group' => 'Automation', 'label' => 'Community Q&A automation', 'type' => 'bool', 'default' => true],
            'automation.community.daily_limit' => ['group' => 'Automation', 'label' => 'Community Q&A per day', 'type' => 'int', 'default' => 10],
            'automation.blog.enabled' => ['group' => 'Automation', 'label' => 'Blog automation', 'type' => 'bool', 'default' => true],
            'automation.blog.daily_limit' => ['group' => 'Automation', 'label' => 'Blogs per day', 'type' => 'int', 'default' => 6],
            'automation.knowledge_base.enabled' => ['group' => 'Automation', 'label' => 'Knowledge Base automation', 'type' => 'bool', 'default' => true],
            'automation.knowledge_base.daily_limit' => ['group' => 'Automation', 'label' => 'KB articles per day', 'type' => 'int', 'default' => 10],
            'automation.release_note.enabled' => ['group' => 'Automation', 'label' => 'Release notes automation', 'type' => 'bool', 'default' => true],
            'automation.workflow_guide.enabled' => ['group' => 'Automation', 'label' => 'Workflow guides automation', 'type' => 'bool', 'default' => true],

            // ── Reports ─────────────────────────────────────────────────────
            'reports.daily.recipients' => ['group' => 'Reports', 'label' => 'Daily analytics report recipients', 'type' => 'email_list', 'default' => 'pno@aicountly.com',
                'help' => 'Comma-separated. Sent every morning at 08:00 IST.'],

            // ── SEO data ────────────────────────────────────────────────────
            'seo.dataforseo.login' => ['group' => 'SEO data', 'label' => 'DataForSEO login', 'type' => 'string', 'env' => 'DATAFORSEO_LOGIN'],
            'seo.dataforseo.password' => ['group' => 'SEO data', 'label' => 'DataForSEO password', 'type' => 'secret', 'env' => 'DATAFORSEO_PASSWORD'],

            // ── Social ──────────────────────────────────────────────────────
            'social.socialapi.api_key' => ['group' => 'Social', 'label' => 'socialapi.ai API key', 'type' => 'secret', 'env' => 'SOCIALAPI_API_KEY'],
            'social.socialapi.base_url' => ['group' => 'Social', 'label' => 'socialapi.ai API base URL', 'type' => 'string', 'env' => 'SOCIALAPI_BASE_URL'],
        ];
    }

    public static function isSecret(string $key): bool
    {
        return (self::registry()[$key]['type'] ?? null) === 'secret';
    }

    /** A non-secret value: saved setting → env fallback → registry default → $default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::isSecret($key)) {
            throw new RuntimeException("{$key} is a secret; read it with ReachConfig::secret().");
        }
        $stored = self::stored()[$key] ?? null;
        if ($stored !== null && $stored !== '') {
            return self::cast($key, $stored);
        }
        $meta = self::registry()[$key] ?? [];
        if (isset($meta['env'])) {
            $env = self::env((string) $meta['env']);
            if ($env !== '') {
                return self::cast($key, $env);
            }
        }
        return array_key_exists('default', $meta) ? $meta['default'] : $default;
    }

    /** A secret: decrypted saved value → env fallback → null. */
    public static function secret(string $key): ?string
    {
        $stored = self::stored()[$key] ?? null;
        if (is_array($stored) && isset($stored['__enc'])) {
            $plain = self::decrypt((string) $stored['__enc']);
            if ($plain !== null && $plain !== '') {
                return $plain;
            }
        }
        $env = (string) (self::registry()[$key]['env'] ?? '');
        $val = $env !== '' ? self::env($env) : '';
        return $val !== '' ? $val : null;
    }

    /** Where a value currently comes from: 'settings', 'env', 'default' or 'unset'. */
    public static function source(string $key): string
    {
        $stored = self::stored()[$key] ?? null;
        if ($stored !== null && $stored !== '' && ! (is_array($stored) && isset($stored['__enc']) && self::decrypt((string) $stored['__enc']) === null)) {
            return 'settings';
        }
        $meta = self::registry()[$key] ?? [];
        if (isset($meta['env']) && self::env((string) $meta['env']) !== '') {
            return 'env';
        }
        return array_key_exists('default', $meta) ? 'default' : 'unset';
    }

    /** Vetting model for a content type, falling back to the default vetting model. */
    public static function vettingModel(string $contentType): string
    {
        $specific = $contentType === 'insights' ? 'ai.insights.model' : 'ai.vetting.model.' . $contentType;
        $model    = isset(self::registry()[$specific]) ? trim((string) self::get($specific, '')) : '';
        return $model !== '' ? $model : trim((string) self::get('ai.vetting.model.default'));
    }

    /**
     * Save a value. Secrets are encrypted; an empty secret clears it (falls
     * back to env). Unknown keys are refused so a typo cannot silently create
     * a setting nothing reads.
     */
    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        $meta = self::registry()[$key] ?? null;
        if ($meta === null) {
            throw new \InvalidArgumentException("Unknown setting: {$key}");
        }
        $model = new SettingModel();
        if ($meta['type'] === 'secret') {
            $plain = trim((string) $value);
            $model->setSetting($key, $plain === '' ? null : ['__enc' => self::encrypt($plain)], $userId);
        } else {
            $model->setSetting($key, self::validate($key, $value), $userId);
        }
        self::$cache = null;
    }

    /**
     * The registry with current values for the settings screen. Secrets are
     * never returned — only whether one is set and where it comes from.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        $out = [];
        foreach (self::registry() as $key => $meta) {
            $row = [
                'key'    => $key,
                'group'  => $meta['group'],
                'label'  => $meta['label'],
                'type'   => $meta['type'],
                'help'   => $meta['help'] ?? null,
                'source' => self::source($key),
            ];
            if ($meta['type'] === 'secret') {
                $row['is_set'] = self::secret($key) !== null;
                $row['value']  = null;
            } else {
                $row['value']   = self::get($key);
                $row['default'] = $meta['default'] ?? null;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** Drop the per-request cache (tests, or after a write elsewhere). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = (new SettingModel())->all();
            } catch (\Throwable) {
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    private static function cast(string $key, mixed $value): mixed
    {
        return match (self::registry()[$key]['type'] ?? 'string') {
            'int'   => (int) $value,
            'bool'  => is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true),
            default => is_string($value) ? trim($value) : $value,
        };
    }

    private static function validate(string $key, mixed $value): mixed
    {
        $type = self::registry()[$key]['type'];
        if ($type === 'int') {
            if (! is_numeric($value) || (int) $value < 0) {
                throw new \InvalidArgumentException("{$key} must be a non-negative number.");
            }
            return (int) $value;
        }
        if ($type === 'bool') {
            return self::cast($key, $value);
        }
        $str = trim((string) $value);
        if ($type === 'email_list' && $str !== '') {
            foreach (array_filter(array_map('trim', explode(',', $str))) as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("{$key}: '{$email}' is not a valid email address.");
                }
            }
        }
        if ($type === 'model' && $str !== '' && ! preg_match('/^[A-Za-z0-9._\-\/]{2,120}$/', $str)) {
            throw new \InvalidArgumentException("{$key}: not a valid model id.");
        }
        return $str;
    }

    private static function env(string $name): string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (($v === false || $v === null) && function_exists('env')) {
            $v = env($name);
        }
        return is_string($v) ? trim($v) : '';
    }

    private static function key(): string
    {
        $material = self::env('REACH_SETTINGS_KEY');
        if ($material === '') {
            $material = self::env('JWT_SECRET');
        }
        if ($material === '') {
            throw new RuntimeException('Set REACH_SETTINGS_KEY (or JWT_SECRET) to store secrets in settings.');
        }
        return hash('sha256', 'reach-settings-v1|' . $material, true);
    }

    private static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::ENC_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    private static function decrypt(string $blob): ?string
    {
        if (! str_starts_with($blob, self::ENC_PREFIX)) {
            return null;
        }
        $raw = base64_decode(substr($blob, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        try {
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        } catch (\Throwable) {
            return null;
        }
        return $plain === false ? null : $plain;
    }
}
