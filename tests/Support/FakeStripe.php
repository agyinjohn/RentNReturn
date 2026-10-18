<?php

declare(strict_types=1);

namespace Tests\Support;

/** Helpers around tests/fake_stripe.php and Stripe webhook signing. */
final class FakeStripe
{
    public const SECRET_KEY = 'sk_test_fake';
    public const WEBHOOK_SECRET = 'whsec_test_secret';

    public function __construct(public readonly string $url)
    {
    }

    public static function start(): self
    {
        $url = TestEnvironment::server(['__script' => 'fake_stripe']);
        $fake = new self($url);
        @unlink($fake->file('json'));
        @unlink($fake->file('log'));
        return $fake;
    }

    /** Environment for an app server that uses this fake Stripe. */
    public function appEnv(array $extra = []): array
    {
        return $extra + ['STRIPE_SECRET_KEY' => self::SECRET_KEY, 'STRIPE_WEBHOOK_SECRET' => self::WEBHOOK_SECRET, 'STRIPE_API_BASE' => $this->url];
    }

    /** @return array<int, array{method: string, path: string, params: array, idempotency_key: ?string}> */
    public function requests(?string $path = null): array
    {
        $lines = is_file($this->file('log')) ? file($this->file('log'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $all = array_map(static fn (string $l): array => json_decode($l, true), $lines ?: []);
        return array_values(array_filter($all, static fn (array $r): bool => $path === null || $r['path'] === $path));
    }

    public function config(array $settings): void
    {
        $this->post('/__test/config', $settings);
    }

    public function setAccount(string $accountId, array $fields): void
    {
        $this->post('/__test/accounts/' . $accountId, $fields);
    }

    /** Build a Stripe event and its Stripe-Signature header (signed exactly like Stripe does). */
    public static function event(string $type, array $object, array $overrides = []): array
    {
        $payload = json_encode($overrides + [
            'id' => 'evt_' . bin2hex(random_bytes(10)),
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'created' => time(),
            'api_version' => '2026-09-30.endive',
            'data' => ['object' => $object],
        ]);
        return [$payload, self::sign($payload)];
    }

    public static function sign(string $payload, ?int $timestamp = null, string $secret = self::WEBHOOK_SECRET): string
    {
        $t = $timestamp ?? time();
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secret);
    }

    /** POST a webhook to an app server. Returns [status, body]. */
    public static function deliver(string $appUrl, string $payload, string $signature): array
    {
        $ch = self::webhookHandle($appUrl, $payload, $signature);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body];
    }

    public static function webhookHandle(string $appUrl, string $payload, string $signature): \CurlHandle
    {
        $ch = curl_init($appUrl . '/webhooks/stripe');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Stripe-Signature: ' . $signature],
            CURLOPT_TIMEOUT => 30,
        ]);
        return $ch;
    }

    private function post(string $path, array $fields): void
    {
        $ch = curl_init($this->url . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function file(string $ext): string
    {
        return sys_get_temp_dir() . '/rnr-fake-stripe-' . parse_url($this->url, PHP_URL_PORT) . '.' . $ext;
    }
}
