<?php

namespace App\Libraries\Ai\Vetting;

use App\Libraries\Settings\ReachConfig;

/**
 * Has the vetting model (Gemini; Settings → AI & Automation picks which one
 * per content type) review a draft and decide approve / revise / reject. The
 * decision is final — callers publish on approve, re-vet a revision once, and
 * retire on reject. Every call is recorded in reach_ai_vetting_runs.
 *
 * Fails closed: no key, a provider error or unusable output raise
 * VettingUnavailableException, and the caller leaves the content pending.
 */
class ContentVettingService
{
    private const MAX_ATTEMPTS = 2;

    /** @var callable(string $url, array $body): array{status:int, body:string} */
    private $http;

    /** @var callable(array $row): ?int */
    private $recorder;

    public function __construct(?callable $http = null, ?callable $recorder = null)
    {
        $this->http     = $http ?? [self::class, 'post'];
        $this->recorder = $recorder ?? [self::class, 'record'];
    }

    /**
     * @param array<string, mixed> $draft   the fields to vet (and to publish)
     * @param array<string, mixed> $context facts the model may rely on (date, product facts, source notes)
     */
    public function vet(string $contentType, array $draft, array $context = [], ?string $contentRef = null): VettingResult
    {
        $rubric = Rubrics::for($contentType);
        $model  = ReachConfig::vettingModel($contentType);
        $key    = ReachConfig::secret('ai.gemini.api_key');
        if ($key === null || $model === '') {
            throw new VettingUnavailableException('Vetting is not configured: set the Gemini API key and vetting model in Settings → AI & Automation.');
        }

        $context += ['today' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d'), 'country' => 'India'];
        $payload  = $this->requestBody($rubric, $draft, $context);
        $inputSha = hash('sha256', json_encode([$contentType, $draft], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $started  = hrtime(true);
        $error    = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $res = ($this->http)($this->url($model, $key), $payload);
                if ($res['status'] !== 200) {
                    throw new VettingUnavailableException('Vetting model returned HTTP ' . $res['status'] . ': ' . $this->providerMessage($res['body']));
                }
                $verdict = $this->parse($res['body'], $draft);
                $runId   = ($this->recorder)([
                    'content_type' => $contentType,
                    'content_ref'  => $contentRef,
                    'model'        => $model,
                    'decision'     => $verdict['decision'],
                    'reasons_json' => json_encode($verdict['reasons'], JSON_UNESCAPED_UNICODE),
                    'scores_json'  => json_encode($verdict['scores']),
                    'input_sha256' => $inputSha,
                    'final_json'   => json_encode($verdict['final'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'latency_ms'   => (int) ((hrtime(true) - $started) / 1_000_000),
                    'error'        => null,
                ]);
                return new VettingResult($verdict['decision'], $verdict['final'], $verdict['reasons'], $verdict['scores'], $model, $runId);
            } catch (VettingUnavailableException $e) {
                $error = $e;
            }
        }

        ($this->recorder)([
            'content_type' => $contentType,
            'content_ref'  => $contentRef,
            'model'        => $model,
            'decision'     => 'error',
            'reasons_json' => '[]',
            'scores_json'  => '{}',
            'input_sha256' => $inputSha,
            'final_json'   => null,
            'latency_ms'   => (int) ((hrtime(true) - $started) / 1_000_000),
            'error'        => mb_substr($error->getMessage(), 0, 1000),
        ]);
        throw $error;
    }

    /** @return array<string, mixed> */
    public function requestBody(string $rubric, array $draft, array $context): array
    {
        $prompt = "CONTEXT (facts you may rely on):\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nDRAFT TO VET (JSON):\n" . json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nReturn your verdict. `final_json` must be the complete draft as a JSON object string with exactly the same keys and value types as the draft above (unchanged when approving).";

        return [
            'systemInstruction' => ['parts' => [['text' => $rubric]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig'  => [
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema'   => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'decision'   => ['type' => 'STRING', 'enum' => [VettingResult::APPROVE, VettingResult::REVISE, VettingResult::REJECT]],
                        'reasons'    => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'scores'     => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'accuracy'   => ['type' => 'INTEGER'],
                                'clarity'    => ['type' => 'INTEGER'],
                                'compliance' => ['type' => 'INTEGER'],
                                'tone'       => ['type' => 'INTEGER'],
                            ],
                        ],
                        'final_json' => ['type' => 'STRING'],
                    ],
                    'required'   => ['decision', 'reasons', 'scores', 'final_json'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array{decision: string, reasons: list<string>, scores: array<string, int>, final: array<string, mixed>}
     */
    private function parse(string $body, array $draft): array
    {
        $response = json_decode($body, true);
        $text     = '';
        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (! ($part['thought'] ?? false) && isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $verdict = json_decode(trim($text), true);
        if (! is_array($verdict) || ! in_array($verdict['decision'] ?? null, [VettingResult::APPROVE, VettingResult::REVISE, VettingResult::REJECT], true)) {
            throw new VettingUnavailableException('Vetting model returned no usable verdict.');
        }

        $reasons = array_values(array_filter(array_map('strval', (array) ($verdict['reasons'] ?? [])), static fn ($r) => trim($r) !== ''));
        $scores  = array_map('intval', array_intersect_key((array) ($verdict['scores'] ?? []), array_flip(['accuracy', 'clarity', 'compliance', 'tone'])));

        if ($verdict['decision'] === VettingResult::REJECT) {
            return ['decision' => VettingResult::REJECT, 'reasons' => $reasons, 'scores' => $scores, 'final' => []];
        }
        if ($verdict['decision'] === VettingResult::APPROVE) {
            return ['decision' => VettingResult::APPROVE, 'reasons' => $reasons, 'scores' => $scores, 'final' => $draft];
        }

        $final = json_decode((string) ($verdict['final_json'] ?? ''), true);
        if (! is_array($final)) {
            throw new VettingUnavailableException('Vetting model asked for a revision but sent no usable revised draft.');
        }
        return ['decision' => VettingResult::REVISE, 'reasons' => $reasons, 'scores' => $scores, 'final' => $this->mergeRevision($draft, $final)];
    }

    /**
     * Take the model's value for each draft field only when it has the same
     * shape as the original; a dropped field keeps the original, and keys the
     * draft never had are ignored — so a revision can correct text but cannot
     * restructure what the caller publishes.
     *
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $revised
     * @return array<string, mixed>
     */
    public function mergeRevision(array $draft, array $revised): array
    {
        $out = $draft;
        foreach ($draft as $field => $original) {
            if (! array_key_exists($field, $revised)) {
                continue;
            }
            $candidate = $revised[$field];
            if (is_string($original) && is_string($candidate) && trim($candidate) !== '') {
                $out[$field] = $candidate;
            } elseif (is_array($original) && is_array($candidate) && $candidate !== []) {
                $out[$field] = $candidate;
            } elseif ((is_int($original) || is_float($original) || is_bool($original)) && gettype($candidate) === gettype($original)) {
                $out[$field] = $candidate;
            }
        }
        return $out;
    }

    private function url(string $model, string $key): string
    {
        $base = $_ENV['AI_GEMINI_BASE_URL'] ?? getenv('AI_GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com';
        return rtrim((string) $base, '/') . '/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
    }

    private function providerMessage(string $body): string
    {
        $msg = (string) (json_decode($body, true)['error']['message'] ?? '');
        return mb_substr(preg_replace('/key=[^&\s]+/', 'key=[REDACTED]', $msg) ?? '', 0, 300);
    }

    /** @return array{status: int, body: string} */
    public static function post(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $raw === false ? 0 : $status, 'body' => $raw === false ? '' : (string) $raw];
    }

    /** Insert a vetting run; never lets a logging failure decide the outcome. */
    public static function record(array $row): ?int
    {
        try {
            $db = \Config\Database::connect();
            $db->table('reach_ai_vetting_runs')->insert($row + ['created_at' => gmdate('Y-m-d H:i:s')]);
            return (int) $db->insertID() ?: null;
        } catch (\Throwable $e) {
            log_message('error', '[ContentVetting] could not record run: ' . $e->getMessage());
            return null;
        }
    }
}
