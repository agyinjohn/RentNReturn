<?php

declare(strict_types=1);

namespace App\Sms;

use App\Logger;

/**
 * Sends SMS. Driver 'twilio' uses Twilio's REST API; driver 'log' appends messages to a file for
 * development and tests (refused outside development by ConfigValidator).
 */
final class SmsSender
{
    public function __construct(private array $config)
    {
    }

    /** Returns false (and logs) on failure, never throws, so callers can show a friendly message. */
    public function send(string $to, string $body): bool
    {
        try {
            if ($this->config['driver'] === 'twilio') {
                $this->sendTwilio($to, $body);
            } else {
                $this->sendLog($to, $body);
            }
            Logger::info('SMS sent', ['driver' => $this->config['driver']]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('SMS delivery failed', ['driver' => $this->config['driver'], 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function driverName(): string
    {
        return $this->config['driver'];
    }

    private function sendTwilio(string $to, string $body): void
    {
        $c = $this->config;
        $fields = ['To' => $to, 'Body' => $body];
        // A Messaging Service SID (MG...) lets Twilio pick the sender; otherwise use a fixed From number.
        if (str_starts_with($c['twilio_from'], 'MG')) {
            $fields['MessagingServiceSid'] = $c['twilio_from'];
        } else {
            $fields['From'] = $c['twilio_from'];
        }
        $ch = curl_init(rtrim($c['twilio_base_url'], '/') . '/2010-04-01/Accounts/' . rawurlencode($c['twilio_sid']) . '/Messages.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_USERPWD => $c['twilio_sid'] . ':' . $c['twilio_token'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new \RuntimeException('Twilio request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $data = json_decode((string) $response, true);
            throw new \RuntimeException('Twilio HTTP ' . $status . ': ' . ($data['message'] ?? 'unknown error'));
        }
    }

    private function sendLog(string $to, string $body): void
    {
        $path = $this->config['log_path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $line = json_encode(['ts' => gmdate('Y-m-d\TH:i:s\Z'), 'to' => $to, 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Could not write SMS log at ' . $path);
        }
    }
}
