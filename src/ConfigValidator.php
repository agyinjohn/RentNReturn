<?php

declare(strict_types=1);

namespace App;

/**
 * Refuses to run staging/production with unsafe settings, so a misconfigured server fails loudly
 * at deploy time instead of silently exposing debug output or sending sessions over plain HTTP.
 */
final class ConfigValidator
{
    /** @return string[] problems found (empty when safe) */
    public static function problems(array $config): array
    {
        $env = $config['env'] ?? 'development';
        if (!in_array($env, ['development', 'staging', 'production'], true)) {
            return ["APP_ENV must be development, staging or production (got '{$env}')."];
        }
        if ($env === 'development') {
            return [];
        }

        $problems = [];
        if (!empty($config['debug'])) {
            $problems[] = 'APP_DEBUG must be 0 outside development.';
        }
        if (!str_starts_with((string) ($config['url'] ?? ''), 'https://')) {
            $problems[] = 'APP_URL must start with https:// outside development.';
        }
        if (empty($config['session']['secure'])) {
            $problems[] = 'Session cookies must be Secure outside development.';
        }
        if (($config['mail']['driver'] ?? 'log') !== 'smtp') {
            $problems[] = 'MAIL_DRIVER must be smtp outside development (the log driver does not deliver email).';
        } elseif (($config['mail']['host'] ?? '') === '') {
            $problems[] = 'MAIL_HOST is required when MAIL_DRIVER=smtp.';
        }
        $sms = $config['sms'] ?? ['driver' => 'log'];
        if (($sms['driver'] ?? 'log') !== 'twilio') {
            $problems[] = 'SMS_DRIVER must be twilio outside development (the log driver does not send texts).';
        } elseif (($sms['twilio_sid'] ?? '') === '' || ($sms['twilio_token'] ?? '') === '' || ($sms['twilio_from'] ?? '') === '') {
            $problems[] = 'TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM are required when SMS_DRIVER=twilio.';
        }
        if (($config['identity']['provider'] ?? 'none') === 'simulated') {
            $problems[] = 'IDENTITY_PROVIDER=simulated is for development only (anyone could verify themselves).';
        }
        if (($config['database']['password'] ?? '') === '') {
            $problems[] = 'DB_PASSWORD must be set outside development.';
        }
        return $problems;
    }

    public static function assertSafe(array $config): void
    {
        $problems = self::problems($config);
        if ($problems !== []) {
            throw new \RuntimeException('Unsafe configuration for APP_ENV=' . ($config['env'] ?? '?') . ': ' . implode(' ', $problems));
        }
    }
}
