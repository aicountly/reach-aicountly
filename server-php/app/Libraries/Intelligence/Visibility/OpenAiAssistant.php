<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

use App\Libraries\Settings\ReachConfig;

/**
 * OpenAI Chat Completions — the model behind ChatGPT, asked over the API.
 * Plain chat models answer from what they learned in training (no live web
 * search); a search-enabled model id can be set in Settings instead, and any
 * url_citation annotations it returns are kept as cited sources.
 */
final class OpenAiAssistant implements AssistantClient
{
    /** @var callable(string, list<string>, array): array{status:int, body:string} */
    private $http;

    public function __construct(
        ?callable $http = null,
        private ?string $apiKey = null,
        private ?string $modelId = null,
    ) {
        $this->http    = $http ?? [AssistantHttp::class, 'post'];
        $this->apiKey  ??= ReachConfig::secret('ai.openai.api_key');
        $this->modelId ??= trim((string) ReachConfig::get('ai.openai.chat_model', 'gpt-5-mini'));
    }

    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'ChatGPT (OpenAI API)';
    }

    public function model(): string
    {
        return (string) $this->modelId;
    }

    public function unavailableReason(): ?string
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            return 'No OpenAI API key (Settings → AI & Automation).';
        }

        return $this->model() === '' ? 'No OpenAI model set.' : null;
    }

    public function ask(string $question): AssistantAnswer
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new AssistantException($reason);
        }

        $model     = $this->model();
        $reasoning = preg_match('/^(gpt-5|o[1-9])/i', $model) === 1;
        $body      = [
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $question]],
        ];
        if ($reasoning) {
            $body['max_completion_tokens'] = 6000;
            $body['reasoning_effort']      = 'low';
        } elseif (! str_contains($model, 'search')) {
            $body['max_tokens'] = 1500;
        }

        $base = rtrim(AssistantHttp::env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $res  = ($this->http)($base . '/chat/completions', ['Authorization: Bearer ' . $this->apiKey], $body);
        if ($res['status'] !== 200) {
            throw new AssistantException('OpenAI returned HTTP ' . $res['status'] . ': ' . AssistantHttp::providerMessage($res['body']));
        }

        return self::parse($res['body'], $model);
    }

    public static function parse(string $body, string $model): AssistantAnswer
    {
        $json    = json_decode($body, true);
        $message = is_array($json) ? ($json['choices'][0]['message'] ?? null) : null;
        $text    = is_array($message) ? (string) ($message['content'] ?? '') : '';
        if (trim($text) === '') {
            $why = (string) ($json['choices'][0]['finish_reason'] ?? 'empty');
            throw new AssistantException('OpenAI returned no answer text (' . $why . ').');
        }

        $citations = [];
        foreach ((array) ($message['annotations'] ?? []) as $a) {
            $url = (string) ($a['url_citation']['url'] ?? '');
            if ($url !== '') {
                $citations[] = ['url' => $url, 'title' => (string) ($a['url_citation']['title'] ?? '')];
            }
        }
        $tokens = $json['usage']['total_tokens'] ?? null;

        return new AssistantAnswer('openai', (string) ($json['model'] ?? $model), trim($text), $citations, is_numeric($tokens) ? (int) $tokens : null);
    }
}
