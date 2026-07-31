<?php

declare(strict_types=1);

namespace App\Libraries\Blog\Verification;

use App\Libraries\Ai\AiGenerationInput;
use App\Libraries\Ai\AiProviderException;
use App\Libraries\Ai\AiProviderInterface;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Ai\Providers\PerplexityProvider;
use App\Libraries\Blog\BlogFeatureFlags;

/**
 * Verifies factual claims using the Perplexity provider with fail-closed semantics.
 */
class FactVerificationService
{
    private AiProviderRegistry $registry;
    private BlogFeatureFlags $featureFlags;
    private CrossReviewRouter $router;

    public function __construct(
        ?AiProviderRegistry $registry = null,
        ?BlogFeatureFlags $featureFlags = null,
        ?CrossReviewRouter $router = null,
    ) {
        $this->registry     = $registry ?? new AiProviderRegistry();
        $this->featureFlags = $featureFlags ?? new BlogFeatureFlags();
        $this->router       = $router ?? new CrossReviewRouter();
    }

    /**
     * @param list<array{text: string, claim_type?: string, risk?: string}> $claims
     * @param array<string, mixed> $opts
     *
     * @return array{
     *     status: 'passed'|'failed'|'unavailable',
     *     pass_rate: float,
     *     unsupported: list<array<string, mixed>>,
     *     claims: list<array<string, mixed>>,
     *     threshold: float,
     *     provider: string|null
     * }
     */
    public function verify(array $claims, array $opts = []): array
    {
        $threshold = $this->passThreshold();
        $providerKey = (string) ($opts['provider'] ?? $this->router->verifierKey());

        if (! $this->featureFlags->isEnabled('fact_verification')) {
            return $this->buildResult(
                status:      'passed',
                passRate:    1.0,
                unsupported: [],
                claims:      $this->annotateClaims($claims, []),
                threshold:   $threshold,
                provider:    null,
            );
        }

        try {
            $provider = $this->resolveVerifier($providerKey, $opts);
        } catch (\Throwable) {
            return $this->unavailableResult($claims, $threshold, $providerKey);
        }

        if ($provider === null || ! $provider->isConfigured()) {
            return $this->unavailableResult($claims, $threshold, $providerKey);
        }

        try {
            $verification = $this->verifyWithProvider($provider, $claims, $opts);
        } catch (\Throwable) {
            return $this->unavailableResult($claims, $threshold, $providerKey);
        }

        $authoritativeHosts = $this->authoritativeHosts();
        $unsupported        = [];
        $verifiedClaims     = [];

        foreach ($verification['claims'] as $index => $claimResult) {
            $original = $claims[$index] ?? ['text' => $claimResult['text'] ?? ''];
            $risk     = strtoupper((string) ($original['risk'] ?? 'LOW'));
            $sources  = $claimResult['sources'] ?? [];
            $verified = (bool) ($claimResult['verified'] ?? false);

            if ($risk === 'HIGH' && ! $this->hasAuthoritativeSource($sources, $authoritativeHosts)) {
                $unsupported[] = array_merge($original, [
                    'reason'  => 'Missing authoritative source for HIGH-risk claim.',
                    'sources' => $sources,
                ]);
                $verified = false;
            }

            $verifiedClaims[] = array_merge($original, [
                'verified' => $verified,
                'sources'  => $sources,
            ]);
        }

        $evaluatedCount = count($verifiedClaims);
        $verifiedCount  = count(array_filter(
            $verifiedClaims,
            static fn (array $claim) => ($claim['verified'] ?? false) === true,
        ));

        $passRate = $evaluatedCount > 0 ? $verifiedCount / $evaluatedCount : 1.0;
        $status   = ($passRate >= $threshold && $unsupported === []) ? 'passed' : 'failed';

        return $this->buildResult(
            status:      $status,
            passRate:    $passRate,
            unsupported: $unsupported,
            claims:      $verifiedClaims,
            threshold:   $threshold,
            provider:    $providerKey,
        );
    }

    /**
     * @param array<string, mixed> $verificationResult
     */
    public function isPublishable(array $verificationResult): bool
    {
        if (($verificationResult['status'] ?? '') !== 'passed') {
            return false;
        }

        $threshold = (float) ($verificationResult['threshold'] ?? $this->passThreshold());
        $passRate  = (float) ($verificationResult['pass_rate'] ?? 0.0);

        if ($passRate < $threshold) {
            return false;
        }

        foreach ($verificationResult['unsupported'] ?? [] as $unsupported) {
            if (strtoupper((string) ($unsupported['risk'] ?? '')) === 'HIGH') {
                return false;
            }
        }

        foreach ($verificationResult['claims'] ?? [] as $claim) {
            $risk = strtoupper((string) ($claim['risk'] ?? ''));
            if ($risk !== 'HIGH') {
                continue;
            }

            $isUnsupported = false;
            foreach ($verificationResult['unsupported'] ?? [] as $unsupported) {
                if (($unsupported['text'] ?? null) === ($claim['text'] ?? null)) {
                    $isUnsupported = true;
                    break;
                }
            }

            if ($isUnsupported) {
                return false;
            }
        }

        return true;
    }

    private function passThreshold(): float
    {
        $raw = $_ENV['BLOG_VERIFICATION_PASS_THRESHOLD']
            ?? getenv('BLOG_VERIFICATION_PASS_THRESHOLD')
            ?: '0.95';

        return (float) $raw;
    }

    /**
     * @return list<string>
     */
    private function authoritativeHosts(): array
    {
        $raw = $_ENV['BLOG_AUTHORITATIVE_SOURCE_HOSTS']
            ?? getenv('BLOG_AUTHORITATIVE_SOURCE_HOSTS')
            ?: 'incometax.gov.in,gst.gov.in,mca.gov.in,rbi.org.in,sebi.gov.in,egazette.gov.in';

        $hosts = array_map('trim', explode(',', (string) $raw));

        return array_values(array_filter($hosts, static fn (string $host) => $host !== ''));
    }

    /**
     * @param list<string> $sources
     * @param list<string> $allowlist
     */
    private function hasAuthoritativeSource(array $sources, array $allowlist): bool
    {
        foreach ($sources as $source) {
            if (! is_string($source) || $source === '') {
                continue;
            }

            $host = parse_url($source, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                continue;
            }

            $host = strtolower($host);
            foreach ($allowlist as $allowed) {
                $allowed = strtolower($allowed);
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array{text: string, claim_type?: string, risk?: string}> $claims
     * @param array<string, mixed> $opts
     */
    private function resolveVerifier(string $providerKey, array $opts): ?AiProviderInterface
    {
        if (isset($opts['verifier']) && $opts['verifier'] instanceof AiProviderInterface) {
            return $opts['verifier'];
        }

        return $this->registry->resolve($providerKey);
    }

    /**
     * @param list<array{text: string, claim_type?: string, risk?: string}> $claims
     * @param array<string, mixed> $opts
     *
     * @return array{claims: list<array{text: string, verified: bool, sources: list<string>}>}
     */
    private function verifyWithProvider(AiProviderInterface $provider, array $claims, array $opts): array
    {
        if (isset($opts['verification_response']) && is_array($opts['verification_response'])) {
            return $this->normalizeVerificationResponse($opts['verification_response'], $claims);
        }

        $claimTexts = array_map(
            static fn (array $claim) => (string) ($claim['text'] ?? ''),
            $claims,
        );

        $schema = [
            'type'       => 'object',
            'properties' => [
                'claims' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'text'     => ['type' => 'string'],
                            'verified' => ['type' => 'boolean'],
                            'sources'  => [
                                'type'  => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['text', 'verified'],
                    ],
                ],
            ],
            'required' => ['claims'],
        ];

        $input = new AiGenerationInput(
            systemPrompt:    'Verify each claim against authoritative sources. Return JSON only.',
            userPrompt:      json_encode(['claims' => $claimTexts], JSON_THROW_ON_ERROR),
            outputSchema:    $schema,
            modelKey:        $opts['model'] ?? (new PerplexityProvider())->resolveModelKey(null),
            maxOutputTokens: 4096,
        );

        try {
            $result = $provider->generate($input);
        } catch (AiProviderException $e) {
            throw $e;
        }

        $parsed = $result->parsedJson ?? [];
        if (isset($parsed['_citations']) && is_array($parsed['_citations'])) {
            $citations = $parsed['_citations'];
            unset($parsed['_citations']);

            if (empty($parsed['claims']) && $citations !== []) {
                $parsed['claims'] = array_map(
                    static fn (string $text) => [
                        'text'     => $text,
                        'verified' => true,
                        'sources'  => $citations,
                    ],
                    $claimTexts,
                );
            }
        }

        return $this->normalizeVerificationResponse($parsed, $claims);
    }

    /**
     * @param array<string, mixed> $response
     * @param list<array{text: string, claim_type?: string, risk?: string}> $claims
     *
     * @return array{claims: list<array{text: string, verified: bool, sources: list<string>}>}
     */
    private function normalizeVerificationResponse(array $response, array $claims): array
    {
        $results = [];
        $items   = $response['claims'] ?? [];

        foreach ($claims as $index => $claim) {
            $item = is_array($items[$index] ?? null) ? $items[$index] : [];
            $sources = $item['sources'] ?? $response['_citations'] ?? [];
            if (! is_array($sources)) {
                $sources = [];
            }

            $results[] = [
                'text'     => (string) ($item['text'] ?? $claim['text'] ?? ''),
                'verified' => (bool) ($item['verified'] ?? false),
                'sources'  => array_values(array_filter(
                    $sources,
                    static fn ($source) => is_string($source) && $source !== '',
                )),
            ];
        }

        return ['claims' => $results];
    }

    /**
     * @param list<array{text: string, claim_type?: string, risk?: string}> $claims
     *
     * @return list<array<string, mixed>>
     */
    private function annotateClaims(array $claims, array $verificationClaims): array
    {
        if ($verificationClaims === []) {
            return array_map(
                static fn (array $claim) => array_merge($claim, ['verified' => true, 'sources' => []]),
                $claims,
            );
        }

        return $verificationClaims;
    }

    /**
     * @param list<array{text: string, claim_type?: string, risk?: string}> $claims
     *
     * @return array{
     *     status: 'unavailable',
     *     pass_rate: float,
     *     unsupported: list<array<string, mixed>>,
     *     claims: list<array<string, mixed>>,
     *     threshold: float,
     *     provider: string|null
     * }
     */
    private function unavailableResult(array $claims, float $threshold, ?string $providerKey): array
    {
        return $this->buildResult(
            status:      'unavailable',
            passRate:    0.0,
            unsupported: [],
            claims:      array_map(
                static fn (array $claim) => array_merge($claim, ['verified' => false, 'sources' => []]),
                $claims,
            ),
            threshold:   $threshold,
            provider:    $providerKey,
        );
    }

    /**
     * @param list<array<string, mixed>> $unsupported
     * @param list<array<string, mixed>> $claims
     *
     * @return array{
     *     status: 'passed'|'failed'|'unavailable',
     *     pass_rate: float,
     *     unsupported: list<array<string, mixed>>,
     *     claims: list<array<string, mixed>>,
     *     threshold: float,
     *     provider: string|null
     * }
     */
    private function buildResult(
        string $status,
        float $passRate,
        array $unsupported,
        array $claims,
        float $threshold,
        ?string $provider,
    ): array {
        return [
            'status'      => $status,
            'pass_rate'   => $passRate,
            'unsupported' => $unsupported,
            'claims'      => $claims,
            'threshold'   => $threshold,
            'provider'    => $provider,
        ];
    }
}
