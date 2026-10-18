<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Libraries\Settings\ReachConfig;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @covers \App\Libraries\Settings\ReachConfig
 */
final class ReachConfigTest extends CIUnitTestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['AI_GEMINI_API_KEY', 'AI_GEMINI_DEFAULT_MODEL', 'AI_OPENAI_IMAGE_MODEL', 'JWT_SECRET', 'REACH_SETTINGS_KEY'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        ReachConfig::flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        ReachConfig::flush();
        parent::tearDown();
    }

    public function testDefaultsAndEnvFallbacks(): void
    {
        $this->assertSame('gpt-image-2.5-flare', ReachConfig::get('ai.image.model'));
        $this->assertSame('pno@aicountly.com', ReachConfig::get('reports.daily.recipients'));
        $this->assertSame(6, ReachConfig::get('automation.blog.daily_limit'));
        $this->assertSame('gemini-2.5-flash', ReachConfig::vettingModel('community'));

        $_ENV['AI_GEMINI_DEFAULT_MODEL'] = 'gemini-x';
        $this->assertSame('gemini-x', ReachConfig::vettingModel('blog'));
        $this->assertSame('env', ReachConfig::source('ai.vetting.model.default'));
    }

    public function testSecretsComeFromEnvWhenNotSavedAndAreNeverDescribed(): void
    {
        $this->assertNull(ReachConfig::secret('ai.gemini.api_key'));
        $_ENV['AI_GEMINI_API_KEY'] = 'abc';
        $this->assertSame('abc', ReachConfig::secret('ai.gemini.api_key'));

        $row = array_values(array_filter(ReachConfig::describe(), static fn ($r) => $r['key'] === 'ai.gemini.api_key'))[0];
        $this->assertTrue($row['is_set']);
        $this->assertNull($row['value']);
        $this->assertSame('env', $row['source']);
    }

    public function testReadingASecretAsPlainValueIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        ReachConfig::get('ai.openai.api_key');
    }

    public function testEncryptionRoundTripsAndRejectsTampering(): void
    {
        $_ENV['JWT_SECRET'] = 'a-test-secret';
        $enc  = new \ReflectionMethod(ReachConfig::class, 'encrypt');
        $dec  = new \ReflectionMethod(ReachConfig::class, 'decrypt');
        $blob = $enc->invoke(null, 'sk-live-123');

        $this->assertStringStartsWith('v1:', $blob);
        $this->assertStringNotContainsString('sk-live-123', $blob);
        $this->assertSame('sk-live-123', $dec->invoke(null, $blob));
        $this->assertNull($dec->invoke(null, 'v1:' . base64_encode(str_repeat('x', 64))));

        $_ENV['REACH_SETTINGS_KEY'] = 'rotated';
        $this->assertNull($dec->invoke(null, $blob), 'a different key cannot decrypt');
    }

    public function testValidationRejectsBadValuesAndUnknownKeys(): void
    {
        $validate = new \ReflectionMethod(ReachConfig::class, 'validate');
        $this->assertSame(5, $validate->invoke(null, 'automation.community.daily_limit', '5'));
        $this->assertSame('a@b.com, c@d.in', $validate->invoke(null, 'reports.daily.recipients', ' a@b.com, c@d.in '));

        foreach ([['automation.community.daily_limit', '-1'], ['reports.daily.recipients', 'not-an-email'], ['ai.vetting.model.default', 'bad model!']] as [$k, $v]) {
            try {
                $validate->invoke(null, $k, $v);
                $this->fail("{$k}={$v} should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        ReachConfig::set('no.such.key', 'x');
    }
}
