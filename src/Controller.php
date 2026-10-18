<?php

declare(strict_types=1);

namespace App;

use App\Booking\BookingService;
use App\Mail\Mailer;

abstract class Controller
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    protected function redirect(string $url, int $code = 302): void
    {
        header('Location: ' . $url, true, $code);
        exit;
    }

    protected function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    protected function rateLimiter(): RateLimiter
    {
        return new RateLimiter(Database::get($this->config));
    }

    /** @return array{0:int,1:int} [max attempts, window seconds] for a named limit in config/app.php */
    protected function limit(string $name): array
    {
        return $this->config['rate_limits'][$name];
    }

    protected function mailer(): Mailer
    {
        return new Mailer($this->config);
    }

    protected function bookings(): BookingService
    {
        return new BookingService(Database::get($this->config), $this->config['bookings']);
    }

    protected function tokens(): UserTokens
    {
        return new UserTokens(Database::get($this->config));
    }

    protected function clientIp(): string
    {
        return Security::clientIp($this->config);
    }

    /** Count an AI description request against the user's hourly and daily limits (protects API spend). */
    protected function allowAiRequest(int $userId): bool
    {
        $limiter = $this->rateLimiter();
        [$hourMax, $hourWindow] = $this->limit('ai_describe_user_hour');
        [$dayMax, $dayWindow] = $this->limit('ai_describe_user_day');
        $hourOk = $limiter->attempt('ai:hour:user:' . $userId, $hourMax, $hourWindow);
        $dayOk = $limiter->attempt('ai:day:user:' . $userId, $dayMax, $dayWindow);
        if (!$hourOk || !$dayOk) {
            Logger::warning('AI description rate limited', ['user_id' => $userId]);
            return false;
        }
        return true;
    }

    /** Email a fresh verification link to a user. */
    protected function sendVerificationEmail(array $user): bool
    {
        $token = $this->tokens()->issue((int) $user['id'], UserTokens::EMAIL_VERIFY, $this->config['tokens']['email_verify_ttl']);
        return $this->mailer()->send($user['email'], 'verify_email', [
            'name' => $user['name'],
            'link' => $this->config['url'] . '/verify-email/confirm?token=' . $token,
        ]);
    }
}
