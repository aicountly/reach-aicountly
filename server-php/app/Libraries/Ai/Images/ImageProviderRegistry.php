<?php

declare(strict_types=1);

namespace App\Libraries\Ai\Images;

/**
 * Central registry of available image provider adapters.
 */
class ImageProviderRegistry
{
    /** @var array<string, ImageProviderInterface> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new OpenAiImageProvider());
        $this->register(new GeminiImageProvider());
    }

    public function register(ImageProviderInterface $provider): void
    {
        $this->providers[$provider->getProviderKey()] = $provider;
    }

    /**
     * @throws \InvalidArgumentException if the key is unknown
     */
    public function resolve(string $providerKey): ImageProviderInterface
    {
        if (! isset($this->providers[$providerKey])) {
            throw new \InvalidArgumentException("Unknown image provider: {$providerKey}");
        }

        return $this->providers[$providerKey];
    }

    /**
     * @throws \InvalidArgumentException if the key is unknown
     * @throws \RuntimeException if the resolved provider is not configured
     */
    public function get(string $providerKey): ImageProviderInterface
    {
        $provider = $this->resolve($providerKey);

        if (! $provider->isConfigured()) {
            throw new \RuntimeException("Image provider '{$providerKey}' is not configured in this environment.");
        }

        return $provider;
    }

    /**
     * @return list<string>
     */
    public function allKeys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * @return array<string, ImageProviderInterface>
     */
    public function configuredProviders(): array
    {
        return array_filter(
            $this->providers,
            fn (ImageProviderInterface $p) => $p->isConfigured(),
        );
    }
}
