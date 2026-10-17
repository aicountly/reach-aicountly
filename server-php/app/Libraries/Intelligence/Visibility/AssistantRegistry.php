<?php

declare(strict_types=1);

namespace App\Libraries\Intelligence\Visibility;

use App\Libraries\Settings\ReachConfig;

/**
 * The assistants visibility checks may use, in Settings order
 * (`visibility.providers`). Tests pass their own clients.
 */
final class AssistantRegistry
{
    public const KNOWN = ['gemini', 'openai', 'perplexity'];

    /** @var array<string, AssistantClient> */
    private array $clients = [];

    /** @var list<string> */
    private array $enabled;

    /**
     * @param list<AssistantClient>|null $clients
     * @param list<string>|null          $enabled provider keys switched on
     */
    public function __construct(?array $clients = null, ?array $enabled = null)
    {
        $clients ??= [new GeminiAssistant(), new OpenAiAssistant(), new PerplexityAssistant()];
        foreach ($clients as $client) {
            $this->clients[$client->key()] = $client;
        }
        $this->enabled = $enabled ?? self::enabledFromSettings();
    }

    /** @return list<string> */
    public static function enabledFromSettings(): array
    {
        $raw  = strtolower((string) ReachConfig::get('visibility.providers', 'gemini,openai,perplexity'));
        $keys = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));

        return array_values(array_intersect($keys, self::KNOWN));
    }

    public function get(string $key): ?AssistantClient
    {
        return $this->clients[$key] ?? null;
    }

    /**
     * Every known assistant with whether it will run and, when not, why.
     *
     * @return list<array{key: string, label: string, model: string, enabled: bool, configured: bool, will_run: bool, reason: ?string}>
     */
    public function status(): array
    {
        $out = [];
        foreach ($this->clients as $key => $client) {
            $reason  = $client->unavailableReason();
            $enabled = in_array($key, $this->enabled, true);
            $out[]   = [
                'key'        => $key,
                'label'      => $client->label(),
                'model'      => $client->model(),
                'enabled'    => $enabled,
                'configured' => $reason === null,
                'will_run'   => $enabled && $reason === null,
                'reason'     => ! $enabled ? 'Switched off in Settings → AI & Automation (visibility.providers).' : $reason,
            ];
        }

        return $out;
    }

    /** @return list<AssistantClient> */
    public function runnable(): array
    {
        $out = [];
        foreach ($this->status() as $row) {
            if ($row['will_run']) {
                $out[] = $this->clients[$row['key']];
            }
        }

        return $out;
    }
}
