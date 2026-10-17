<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

use App\Libraries\Settings\ReachConfig;

/**
 * Perplexity Sonar — answers from a live web search and returns the URLs it
 * used. Optional: without a key it is skipped.
 */
final class PerplexityAssistant implements AssistantClient
{
    /** @var callable(string, list<string>, array): array{status:int, body:string} */
    private $http;

    public function __construct(
        ?callable $http = null,
        private ?string $apiKey = null,
        private ?string $modelId = null,
    ) {
        $this->http    = $http ?? [AssistantHttp::class, 'post'];
        $this->apiKey  ??= ReachConfig::secret('ai.perplexity.api_key');
        $this->modelId ??= trim((string) ReachConfig::get('ai.perplexity.model', 'sonar'));
    }

    public function key(): string
    {
        return 'perplexity';
    }

    public function label(): string
    {
        return 'Perplexity';
    }

    public function model(): string
    {
        return (string) $this->modelId;
    }

    public function unavailableReason(): ?string
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            return 'No Perplexity API key (optional; Settings → AI & Automation).';
        }

        return $this->model() === '' ? 'No Perplexity model set.' : null;
    }

    public function ask(string $question): AssistantAnswer
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            throw new AssistantException($reason);
        }

        $base = rtrim(AssistantHttp::env('AI_PERPLEXITY_BASE_URL', 'https://api.perplexity.ai'), '/');
        $res  = ($this->http)($base . '/chat/completions', ['Authorization: Bearer ' . $this->apiKey], [
            'model'    => $this->model(),
            'messages' => [['role' => 'user', 'content' => $question]],
        ]);
        if ($res['status'] !== 200) {
            throw new AssistantException('Perplexity returned HTTP ' . $res['status'] . ': ' . AssistantHttp::providerMessage($res['body']));
        }

        return self::parse($res['body'], $this->model());
    }

    public static function parse(string $body, string $model): AssistantAnswer
    {
        $json = json_decode($body, true);
        $text = is_array($json) ? (string) ($json['choices'][0]['message']['content'] ?? '') : '';
        if (trim($text) === '') {
            throw new AssistantException('Perplexity returned no answer text.');
        }

        $citations = [];
        $seen      = [];
        foreach ((array) ($json['search_results'] ?? []) as $r) {
            $url = is_array($r) ? (string) ($r['url'] ?? '') : '';
            if ($url !== '' && ! isset($seen[$url])) {
                $seen[$url]  = true;
                $citations[] = ['url' => $url, 'title' => (string) ($r['title'] ?? '')];
            }
        }
        foreach ((array) ($json['citations'] ?? []) as $url) {
            if (is_string($url) && $url !== '' && ! isset($seen[$url])) {
                $seen[$url]  = true;
                $citations[] = ['url' => $url];
            }
        }
        $tokens = $json['usage']['total_tokens'] ?? null;

        return new AssistantAnswer('perplexity', (string) ($json['model'] ?? $model), trim($text), $citations, is_numeric($tokens) ? (int) $tokens : null);
    }
}
