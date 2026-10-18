<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Logger;
use App\UserTokens;

class PasswordResetController extends Controller
{
    private const SENT_MESSAGE = 'If an account exists for that email, we have sent a link to reset your password. It expires in 1 hour.';

    public function requestForm(array $params = []): void
    {
        $error = $_SESSION['reset_error'] ?? null;
        $notice = $_SESSION['reset_notice'] ?? null;
        unset($_SESSION['reset_error'], $_SESSION['reset_notice']);
        include $this->config['base_path'] . '/templates/auth/forgot_password.php';
    }

    public function sendLink(array $params = []): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['reset_error'] = 'Please enter a valid email.';
            $this->redirect('/forgot-password');
            return;
        }
        $limiter = $this->rateLimiter();
        [$emailMax, $emailWindow] = $this->limit('password_reset_email');
        [$ipMax, $ipWindow] = $this->limit('password_reset_ip');
        // Count against both limits on every request (no short-circuit).
        $ipOk = $limiter->attempt('reset:ip:' . $this->clientIp(), $ipMax, $ipWindow);
        $emailOk = $limiter->attempt('reset:email:' . $email, $emailMax, $emailWindow);

        if ($ipOk && $emailOk) {
            $stmt = Database::get($this->config)->prepare('SELECT id, email, name FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $token = $this->tokens()->issue((int) $user['id'], UserTokens::PASSWORD_RESET, $this->config['tokens']['password_reset_ttl']);
                $this->mailer()->send($user['email'], 'password_reset', [
                    'name' => $user['name'],
                    'link' => $this->config['url'] . '/reset-password?token=' . $token,
                ]);
                Logger::info('Password reset requested', ['user_id' => (int) $user['id']]);
            }
        } else {
            Logger::warning('Password reset rate limited', ['ip' => $this->clientIp()]);
        }
        // Identical response whether or not the account exists (or the request was rate limited).
        $_SESSION['reset_notice'] = self::SENT_MESSAGE;
        $this->redirect('/forgot-password');
    }

    public function resetForm(array $params = []): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $valid = $this->tokens()->peek($token, UserTokens::PASSWORD_RESET) !== null;
        $error = $_SESSION['reset_error'] ?? null;
        unset($_SESSION['reset_error']);
        include $this->config['base_path'] . '/templates/auth/reset_password.php';
    }

    public function reset(array $params = []): void
    {
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirmation'] ?? '');
        $back = '/reset-password?token=' . urlencode($token);

        if (strlen($password) < 8 || strlen($password) > 72) {
            $_SESSION['reset_error'] = 'Password must be between 8 and 72 characters.';
            $this->redirect($back);
            return;
        }
        if (!hash_equals($password, $confirm)) {
            $_SESSION['reset_error'] = 'The two passwords do not match.';
            $this->redirect($back);
            return;
        }

        $userId = $this->tokens()->consume($token, UserTokens::PASSWORD_RESET);
        if ($userId === null) {
            $_SESSION['reset_error'] = 'That reset link is invalid, expired or has already been used. Please request a new one.';
            $this->redirect('/forgot-password');
            return;
        }

        $pdo = Database::get($this->config);
        // Bumping session_version signs the user out everywhere. Receiving the link also proves they own the email.
        $pdo->prepare('
            UPDATE users
            SET password_hash = ?, session_version = session_version + 1,
                email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
            WHERE id = ?
        ')->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        $this->tokens()->invalidate($userId, UserTokens::PASSWORD_RESET);

        $stmt = $pdo->prepare('SELECT email, name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $this->rateLimiter()->clear('login:email:' . strtolower($user['email']));
        $this->mailer()->send($user['email'], 'password_changed', ['name' => $user['name']]);
        Logger::info('Password reset completed', ['user_id' => $userId]);

        if (session_status() === PHP_SESSION_ACTIVE) {
            Auth::logout();
            Auth::init($this->config);
        }
        $_SESSION['auth_notice'] = 'Your password has been changed. Please log in with your new password.';
        $this->redirect('/login');
    }
}
