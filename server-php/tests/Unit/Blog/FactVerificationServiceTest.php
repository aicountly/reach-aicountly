<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use App\Libraries\Ai\AiProviderInterface;
use App\Libraries\Ai\AiProviderRegistry;
use App\Libraries\Blog\BlogFeatureFlags;
use App\Libraries\Blog\Verification\FactVerificationService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Libraries\Blog\Verification\FactVerificationService
 */
final class FactVerificationServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup = [
            'BLOG_FACT_VERIFICATION_ENABLED'   => $_ENV['BLOG_FACT_VERIFICATION_ENABLED'] ?? null,
            'BLOG_VERIFICATION_PASS_THRESHOLD' => $_ENV['BLOG_VERIFICATION_PASS_THRESHOLD'] ?? null,
            'BLOG_AUTHORITATIVE_SOURCE_HOSTS' => $_ENV['BLOG_AUTHORITATIVE_SOURCE_HOSTS'] ?? null,
            'AI_PERPLEXITY_API_KEY'            => $_ENV['AI_PERPLEXITY_API_KEY'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testFailClosedWhenEnabledAndProviderUnconfigured(): void
    {
        $_ENV['BLOG_FACT_VERIFICATION_ENABLED'] = 'true';
        unset($_ENV['AI_PERPLEXITY_API_KEY']);

        $service = new FactVerificationService(new AiProviderRegistry(), new BlogFeatureFlags());
        $result  = $service->verify([
            ['text' => 'GST rate is 18%.', 'risk' => 'LOW'],
        ]);

        $this->assertSame('unavailable', $result['status']);
        $this->assertFalse($service->isPublishable($result));
    }

    public function testHighRiskClaimWithoutAuthoritativeSourceIsUnsupported(): void
    {
        $_ENV['BLOG_FACT_VERIFICATION_ENABLED'] = 'true';

        $verifier = new FakeVerifierProvider(true);
        $service  = new FactVerificationService();

        $result = $service->verify(
            [['text' => 'The filing due date is 31 March.', 'risk' => 'HIGH']],
            [
                'verifier'              => $verifier,
                'verification_response' => [
                    'claims' => [
                        [
                            'text'     => 'The filing due date is 31 March.',
                            'verified' => true,
                            'sources'  => ['https://example.com/source'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($result['unsupported']);
        $this->assertFalse($service->isPublishable($result));
    }

    public function testPassingCaseWithAuthoritativeSource(): void
    {
        $_ENV['BLOG_FACT_VERIFICATION_ENABLED'] = 'true';
        $_ENV['BLOG_VERIFICATION_PASS_THRESHOLD'] = '0.95';

        $verifier = new FakeVerifierProvider(true);
        $service  = new FactVerificationService();

        $result = $service->verify(
            [['text' => 'GST rate is 18%.', 'risk' => 'HIGH']],
            [
                'verifier'              => $verifier,
                'verification_response' => [
                    'claims' => [
                        [
                            'text'     => 'GST rate is 18%.',
                            'verified' => true,
                            'sources'  => ['https://www.gst.gov.in/rates'],
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame('passed', $result['status']);
        $this->assertGreaterThanOrEqual(0.95, $result['pass_rate']);
        $this->assertEmpty($result['unsupported']);
        $this->assertTrue($service->isPublishable($result));
    }

    public function testProviderThrowReturnsUnavailable(): void
    {
        $_ENV['BLOG_FACT_VERIFICATION_ENABLED'] = 'true';

        $verifier = new FakeVerifierProvider(false);
        $service  = new FactVerificationService();

        $result = $service->verify(
            [['text' => 'Some claim.', 'risk' => 'LOW']],
            ['verifier' => $verifier],
        );

        $this->assertSame('unavailable', $result['status']);
        $this->assertFalse($service->isPublishable($result));
    }
}

final class FakeVerifierProvider implements AiProviderInterface
{
    public function __construct(private readonly bool $shouldSucceed)
    {
    }

    public function getProviderKey(): string
    {
        return 'perplexity';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function healthCheck(): \App\Libraries\Ai\AiProviderHealthResult
    {
        return new \App\Libraries\Ai\AiProviderHealthResult(true, 1);
    }

    public function generate(\App\Libraries\Ai\AiGenerationInput $input): \App\Libraries\Ai\AiGenerationResult
    {
        if (! $this->shouldSucceed) {
            throw new \App\Libraries\Ai\AiProviderException(
                'Provider failed.',
                new \App\Libraries\Ai\AiProviderError(
                    \App\Libraries\Ai\AiProviderError::CATEGORY_PROVIDER_UNAVAIL,
                    'Provider failed.',
                ),
            );
        }

        return new \App\Libraries\Ai\AiGenerationResult(
            rawContent:         '{}',
            parsedJson:         ['claims' => []],
            inputTokens:        1,
            outputTokens:       1,
            totalTokens:        2,
            providerResponseId: 'fake-001',
            durationMs:         1,
            modelKey:           'fake',
            providerKey:        'perplexity',
        );
    }

    public function classifyError(\Throwable $error): \App\Libraries\Ai\AiProviderError
    {
        return new \App\Libraries\Ai\AiProviderError(
            \App\Libraries\Ai\AiProviderError::CATEGORY_UNKNOWN,
            $error->getMessage(),
        );
    }
}
