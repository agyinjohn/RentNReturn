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
            'sms' => ['driver' => 'twilio', 'twilio_sid' => 'AC123', 'twilio_token' => 'tok', 'twilio_from' => '+447700900000'],
            'identity' => ['provider' => 'none'],
            'stripe' => ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_123'],
            'payments' => ['fee_payer' => 'owner', 'platform_fee_bps' => 1000, 'live_approved' => false],
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
            'log sms driver' => [['sms' => ['driver' => 'log']], 'SMS_DRIVER'],
            'twilio without credentials' => [['sms' => ['twilio_token' => '']], 'TWILIO_'],
            'simulated identity provider' => [['identity' => ['provider' => 'simulated']], 'IDENTITY_PROVIDER=simulated'],
            'live stripe key without sign-off' => [['stripe' => ['secret_key' => 'sk_live_abc']], 'Live Stripe keys'],
            'stripe without webhook secret' => [['stripe' => ['webhook_secret' => '']], 'STRIPE_WEBHOOK_SECRET'],
            'publishable key used as secret' => [['stripe' => ['secret_key' => 'pk_test_abc']], 'STRIPE_SECRET_KEY'],
            'unknown fee payer' => [['payments' => ['fee_payer' => 'both']], 'PLATFORM_FEE_PAYER'],
        ];
    }

    public function testLiveStripeKeysAreRefusedEvenInDevelopmentUntilSignedOff(): void
    {
        $dev = ['env' => 'development', 'stripe' => ['secret_key' => 'sk_live_abc'], 'payments' => ['live_approved' => false]];
        $this->assertStringContainsString('Live Stripe keys', implode(' ', ConfigValidator::problems($dev)));
        $this->assertSame([], ConfigValidator::problems($this->config(['stripe' => ['secret_key' => 'sk_live_abc'], 'payments' => ['live_approved' => true]])));
    }

    public function testUnknownEnvironmentIsRefused(): void
    {
        $this->assertNotEmpty(ConfigValidator::problems(['env' => 'prod']));
    }
}
