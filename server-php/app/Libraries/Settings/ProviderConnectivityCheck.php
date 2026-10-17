<?php

namespace App\Libraries\Settings;

/**
 * Live "does this credential work?" checks behind the Test buttons on
 * Settings → AI & Automation, and the Gemini model list for the vetting-model
 * dropdown. Each check makes one cheap authenticated read call; nothing is
 * generated or billed beyond that. Credentials never appear in results.
 */
class ProviderConnectivityCheck
{
    /** @var callable(string $method, string $url, array $headers): array{status:int, body:string} */
    private $http;

    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? [self::class, 'curl'];
    }

    /** @return array{ok: bool, target: string, message: string, detail?: array<string, mixed>} */
    public function check(string $target): array
    {
        return match ($target) {
            'gemini'     => $this->gemini(),
            'openai'     => $this->openai(),
            'perplexity' => $this->perplexity(),
            'dataforseo' => $this->dataforseo(),
            default      => ['ok' => false, 'target' => $target, 'message' => 'Unknown provider.'],
        };
    }

    /**
     * Gemini models that can generate content, as bare ids ("gemini-2.5-flash").
     *
     * @return list<array{id: string, label: string}>
     */
    public function geminiModels(): array
    {
        $key = ReachConfig::secret('ai.gemini.api_key');
        if ($key === null) {
            return [];
        }
        $res = ($this->http)('GET', $this->geminiBase() . '/v1beta/models?pageSize=1000&key=' . rawurlencode($key), []);
        if ($res['status'] !== 200) {
            return [];
        }
        $out = [];
        foreach ((json_decode($res['body'], true)['models'] ?? []) as $m) {
            if (! in_array('generateContent', (array) ($m['supportedGenerationMethods'] ?? []), true)) {
                continue;
            }
            $id    = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
            $out[] = ['id' => $id, 'label' => (string) ($m['displayName'] ?? $id)];
        }
        usort($out, static fn ($a, $b) => strcmp($b['id'], $a['id']));
        return $out;
    }

    private function gemini(): array
    {
        if (ReachConfig::secret('ai.gemini.api_key') === null) {
            return $this->notSet('gemini');
        }
        $models = $this->geminiModels();
        if ($models === []) {
            return ['ok' => false, 'target' => 'gemini', 'message' => 'Gemini rejected the key or returned no models.'];
        }
        $vetting = ReachConfig::vettingModel('community');
        $known   = in_array($vetting, array_column($models, 'id'), true);
        return [
            'ok'      => $known,
            'target'  => 'gemini',
            'message' => $known
                ? 'Connected. Vetting model ' . $vetting . ' is available.'
                : 'Connected, but the vetting model "' . $vetting . '" is not offered for this key — pick one from the list.',
            'detail'  => ['models' => count($models)],
        ];
    }

    private function openai(): array
    {
        $key = ReachConfig::secret('ai.openai.api_key');
        if ($key === null) {
            return $this->notSet('openai');
        }
        $res = ($this->http)('GET', 'https://api.openai.com/v1/models', ['Authorization: Bearer ' . $key]);
        if ($res['status'] !== 200) {
            return ['ok' => false, 'target' => 'openai', 'message' => 'OpenAI rejected the key (HTTP ' . $res['status'] . ').'];
        }
        $ids   = array_column(json_decode($res['body'], true)['data'] ?? [], 'id');
        $image = (string) ReachConfig::get('ai.image.model');
        $has   = in_array($image, $ids, true);
        return [
            'ok'      => $has,
            'target'  => 'openai',
            'message' => $has ? 'Connected. Image model ' . $image . ' is available.' : 'Connected, but image model "' . $image . '" is not available to this key.',
        ];
    }

    private function perplexity(): array
    {
        $key = ReachConfig::secret('ai.perplexity.api_key');
        if ($key === null) {
            return $this->notSet('perplexity');
        }
        $res = ($this->http)('GET', 'https://api.perplexity.ai/models', ['Authorization: Bearer ' . $key]);
        // Perplexity has no free list endpoint on every plan; 401/403 is the
        // only answer that proves the key wrong.
        $ok = ! in_array($res['status'], [401, 403], true) && $res['status'] !== 0;
        return ['ok' => $ok, 'target' => 'perplexity', 'message' => $ok ? 'Key accepted.' : 'Perplexity rejected the key (HTTP ' . $res['status'] . ').'];
    }

    private function dataforseo(): array
    {
        $login    = trim((string) ReachConfig::get('seo.dataforseo.login', ''));
        $password = ReachConfig::secret('seo.dataforseo.password');
        if ($login === '' || $password === null) {
            return $this->notSet('dataforseo');
        }
        $res = ($this->http)('GET', 'https://api.dataforseo.com/v3/appendix/user_data', ['Authorization: Basic ' . base64_encode($login . ':' . $password)]);
        $body = json_decode($res['body'], true) ?? [];
        $ok   = $res['status'] === 200 && (int) ($body['status_code'] ?? 0) === 20000;
        $bal  = $body['tasks'][0]['result'][0]['money']['balance'] ?? null;
        return [
            'ok'      => $ok,
            'target'  => 'dataforseo',
            'message' => $ok ? 'Connected.' . ($bal !== null ? ' Balance: $' . $bal : '') : 'DataForSEO rejected the credentials.',
        ];
    }

    private function notSet(string $target): array
    {
        return ['ok' => false, 'target' => $target, 'message' => 'No credential saved (and none in the environment).'];
    }

    private function geminiBase(): string
    {
        $base = $_ENV['AI_GEMINI_BASE_URL'] ?? getenv('AI_GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com';
        return rtrim((string) $base, '/');
    }

    /** @return array{status: int, body: string} */
    public static function curl(string $method, string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? '' : (string) $body];
    }
}
