<?php

declare(strict_types=1);

namespace App\Libraries\Ai;

use App\Libraries\Ai\Providers\MockAiProvider;
use App\Libraries\Ai\Providers\OpenAiProvider;

/**
 * Phase 3 — Central registry of available AI provider adapters.
 *
 * Adapters are registered once at startup.
 * The mock provider is available in all environments.
 * The mock provider is ONLY used when REACH_AI_MOCK=true or when the requested
 * provider key is explicitly 'mock'. It is never selected automatically in production.
 */
class AiProviderRegistry
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new OpenAiProvider());
        $this->register(new MockAiProvider());
    }

    public function register(AiProviderInterface $provider): void
    {
        $this->providers[$provider->getProviderKey()] = $provider;
    }

    /**
     * Resolve a provider by key.
     *
     * @throws \InvalidArgumentException if the key is unknown
     * @throws \RuntimeException if the resolved provider is not configured (and not mock)
     */
    public function get(string $providerKey): AiProviderInterface
    {
        if (! isset($this->providers[$providerKey])) {
            throw new \InvalidArgumentException("Unknown AI provider: {$providerKey}");
        }

        $provider = $this->providers[$providerKey];

        if ($providerKey !== MockAiProvider::PROVIDER_KEY && ! $provider->isConfigured()) {
            throw new \RuntimeException("AI provider '{$providerKey}' is not configured in this environment.");
        }

        return $provider;
    }

    /**
     * Returns all registered provider keys.
     */
    public function allKeys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Returns only configured, non-mock providers.
     */
    public function configuredProductionProviders(): array
    {
        return array_filter(
            $this->providers,
            fn($p) => $p->getProviderKey() !== MockAiProvider::PROVIDER_KEY && $p->isConfigured(),
        );
    }
}
