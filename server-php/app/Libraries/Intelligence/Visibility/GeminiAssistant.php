<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

use App\Libraries\Settings\ReachConfig;

/**
 * Gemini (Google AI Studio API). With search grounding on — the default —
 * the answer is grounded on Google Search like the Gemini app, and the
 * grounding chunks come back as cited sources.
 */
final class GeminiAssistant implements AssistantClient
{
    /** @var callable(string, list<string>, array): array{status:int, body:string} */
    private $http;

    public function __construct(
        ?callable $http = null,
        private ?string $apiKey = null,
        private ?string $modelId = null,
        private ?bool $grounding = null,
    ) {
        $this->http      = $http ?? [AssistantHttp::class, 'post'];
        $this->apiKey    ??= ReachConfig::secret('ai.gemini.api_key');
        $this->modelId   ??= ReachConfig::vettingModel('insights');
        $this->grounding ??= (bool) ReachConfig::get('visibility.gemini.search_grounding', true);
    }

    public function key(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Gemini';
    }

    public function model(): string
    {
        return (string) $this->modelId;
    }

    public function unavailableReason(): ?string
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            return 'No Gemini API key (Settings → AI & Automation).';
        }
        if ($this->model() === '') {
            return 'No AI Insights / default vetting model set.';
        }

        return null;
    }

    public function ask(string $question): AssistantAnswer
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new AssistantException($reason);
        }

        $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $question]]]]];
        if ($this->grounding) {
            $body['tools'] = [['google_search' => new \stdClass()]];
        }

        $base = rtrim(AssistantHttp::env('AI_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'), '/');
        $url  = $base . '/v1beta/models/' . rawurlencode($this->model()) . ':generateContent?key=' . rawurlencode((string) $this->apiKey);
        $res  = ($this->http)($url, [], $body);
        if ($res['status'] !== 200) {
            throw new AssistantException('Gemini returned HTTP ' . $res['status'] . ': ' . AssistantHttp::providerMessage($res['body']));
        }

        return self::parse($res['body'], $this->model());
    }

    public static function parse(string $body, string $model): AssistantAnswer
    {
        $json      = json_decode($body, true);
        $candidate = is_array($json) ? ($json['candidates'][0] ?? null) : null;
        $text      = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (! ($part['thought'] ?? false) && isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }
        if (trim($text) === '') {
            $why = (string) ($candidate['finishReason'] ?? ($json['promptFeedback']['blockReason'] ?? 'empty'));
            throw new AssistantException('Gemini returned no answer text (' . $why . ').');
        }

        $citations = [];
        foreach ((array) ($candidate['groundingMetadata']['groundingChunks'] ?? []) as $chunk) {
            $uri = (string) ($chunk['web']['uri'] ?? '');
            if ($uri !== '') {
                $citations[] = ['url' => $uri, 'title' => (string) ($chunk['web']['title'] ?? '')];
            }
        }
        $tokens = $json['usageMetadata']['totalTokenCount'] ?? null;

        return new AssistantAnswer('gemini', (string) ($json['modelVersion'] ?? $model), trim($text), $citations, is_numeric($tokens) ? (int) $tokens : null);
    }
}
