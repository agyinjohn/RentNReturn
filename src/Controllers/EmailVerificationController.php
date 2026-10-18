<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Logger;
use App\UserTokens;

class EmailVerificationController extends Controller
{
    /** "Please verify your email" page with a resend button. */
    public function notice(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (Auth::isVerified($user)) {
            $this->redirect('/');
            return;
        }
        $notice = $_SESSION['verify_notice'] ?? null;
        $success = $_SESSION['verify_success'] ?? null;
        $error = $_SESSION['verify_error'] ?? null;
        unset($_SESSION['verify_notice'], $_SESSION['verify_success'], $_SESSION['verify_error']);
        include $this->config['base_path'] . '/templates/auth/verify_notice.php';
    }

    public function resend(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (Auth::isVerified($user)) {
            $this->redirect('/');
            return;
        }
        [$max, $window] = $this->limit('verify_resend_user');
        if (!$this->rateLimiter()->attempt('verify_resend:user:' . $user['id'], $max, $window)) {
            $_SESSION['verify_error'] = 'You have requested several emails recently. Please check your inbox (and spam folder) or try again in an hour.';
        } elseif ($this->sendVerificationEmail($user)) {
            $_SESSION['verify_success'] = 'We have sent a new verification link to ' . $user['email'] . '.';
        } else {
            $_SESSION['verify_error'] = 'We could not send the email just now. Please try again shortly.';
        }
        $this->redirect('/verify-email');
    }

    /** Link from the email. Works whether or not the user is signed in on this device. */
    public function confirm(array $params = []): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $userId = $this->tokens()->consume($token, UserTokens::EMAIL_VERIFY);
        $loggedIn = Auth::check($this->config);
        if ($userId === null) {
            $message = 'That verification link is invalid or has expired.';
            if ($loggedIn) {
                $_SESSION['verify_error'] = $message . ' You can request a new one below.';
                $this->redirect('/verify-email');
            } else {
                $_SESSION['auth_error'] = $message . ' Log in to request a new one.';
                $this->redirect('/login');
            }
            return;
        }
        Database::get($this->config)
            ->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL')
            ->execute([$userId]);
        Logger::info('Email verified', ['user_id' => $userId]);
        if ($loggedIn) {
            $_SESSION['flash_success'] = 'Thanks — your email address is verified.';
            $this->redirect('/');
        } else {
            $_SESSION['auth_notice'] = 'Thanks — your email address is verified. You can now log in.';
            $this->redirect('/login');
        }
    }
}
