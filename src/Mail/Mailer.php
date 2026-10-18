<?php

declare(strict_types=1);

namespace App\Mail;

use App\Logger;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends transactional email. Driver 'smtp' delivers through any SMTP provider (Postmark, SES,
 * Mailgun, Brevo, Microsoft 365...); driver 'log' appends messages to a file for development and tests.
 */
final class Mailer
{
    public function __construct(private array $config)
    {
    }

    /**
     * Render templates/emails/{template}.php and send it. The template sets $subject and $body (plain text).
     * Returns false (and logs) on delivery failure so callers never reveal mail errors to users.
     */
    public function send(string $to, string $template, array $data = []): bool
    {
        [$subject, $body] = $this->render($template, $data + ['appName' => $this->config['name'], 'appUrl' => $this->config['url']]);
        $mail = $this->config['mail'];

        try {
            if ($mail['driver'] === 'smtp') {
                $this->sendSmtp($to, $subject, $body);
            } else {
                $this->sendLog($to, $subject, $body);
            }
            Logger::info('Email sent', ['template' => $template, 'driver' => $mail['driver']]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('Email delivery failed', ['template' => $template, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function render(string $template, array $data): array
    {
        $file = $this->config['base_path'] . '/templates/emails/' . $template . '.php';
        $subject = '';
        $body = '';
        (static function (string $__file, array $__data) use (&$subject, &$body): void {
            extract($__data, EXTR_SKIP);
            include $__file;
        })($file, $data);
        return [$subject, $body];
    }

    private function sendSmtp(string $to, string $subject, string $body): void
    {
        $mail = $this->config['mail'];
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = $mail['host'];
        $m->Port = $mail['port'];
        $m->SMTPAuth = $mail['username'] !== '';
        $m->Username = $mail['username'];
        $m->Password = $mail['password'];
        $m->SMTPSecure = match ($mail['encryption']) {
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            'none' => '',
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };
        $m->SMTPAutoTLS = $mail['encryption'] !== 'none';
        $m->Timeout = 15;
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->setFrom($mail['from_address'], $mail['from_name']);
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->Body = $body;
        $m->isHTML(false);
        $m->send();
    }

    private function sendLog(string $to, string $subject, string $body): void
    {
        $path = $this->config['mail']['log_path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        $entry = json_encode([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ], JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Could not write mail log at ' . $path);
        }
    }
}
