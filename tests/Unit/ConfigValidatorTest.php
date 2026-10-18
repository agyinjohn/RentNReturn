<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ConfigValidator;
use PHPUnit\Framework\TestCase;

/** Step 0 gate: production must have debug off, HTTPS-only APP_URL, secure cookies and real email. */
final class ConfigValidatorTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'env' => 'production',
            'debug' => false,
            'url' => 'https://rentnreturn.co.uk',
            'session' => ['secure' => true],
            'mail' => ['driver' => 'smtp', 'host' => 'smtp.example.com'],
            'database' => ['password' => 'secret'],
        ], $overrides);
    }

    public function testSafeProductionConfigPasses(): void
    {
        $this->assertSame([], ConfigValidator::problems($this->config()));
        ConfigValidator::assertSafe($this->config());
        $this->addToAssertionCount(1);
    }

    public function testDevelopmentIsNotRestricted(): void
    {
        $this->assertSame([], ConfigValidator::problems(['env' => 'development', 'debug' => true, 'url' => 'http://localhost:8080']));
    }

    /** @dataProvider unsafeConfigs */
    public function testUnsafeProductionAndStagingConfigsAreRefused(array $overrides, string $expected): void
    {
        foreach (['production', 'staging'] as $env) {
            $problems = ConfigValidator::problems($this->config($overrides + ['env' => $env]));
            $this->assertNotEmpty($problems);
            $this->assertStringContainsString($expected, implode(' ', $problems));
        }
        $this->expectException(\RuntimeException::class);
        ConfigValidator::assertSafe($this->config($overrides));
    }

    public static function unsafeConfigs(): array
    {
        return [
            'debug on' => [['debug' => true], 'APP_DEBUG'],
            'http url' => [['url' => 'http://rentnreturn.co.uk'], 'https://'],
            'insecure cookies' => [['session' => ['secure' => false]], 'Secure'],
            'log mailer' => [['mail' => ['driver' => 'log']], 'MAIL_DRIVER'],
            'smtp without host' => [['mail' => ['host' => '']], 'MAIL_HOST'],
            'empty db password' => [['database' => ['password' => '']], 'DB_PASSWORD'],
        ];
    }

    public function testUnknownEnvironmentIsRefused(): void
    {
        $this->assertNotEmpty(ConfigValidator::problems(['env' => 'prod']));
    }
}
