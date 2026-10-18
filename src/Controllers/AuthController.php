<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Logger;

class AuthController extends Controller
{
    /** Verified against when the email is unknown, so response time does not reveal whether an account exists. */
    private const DUMMY_HASH = '$2y$10$S7kQMX./cmBJ3vOsJ6h3H.XlQUMWYkpGt0KAp3HB5ZAg9tmStBvJq';

    public function loginForm(array $params = []): void
    {
        if (Auth::check($this->config)) {
            $this->redirect('/');
            return;
        }
        $error = $_SESSION['auth_error'] ?? null;
        $notice = $_SESSION['auth_notice'] ?? null;
        unset($_SESSION['auth_error'], $_SESSION['auth_notice']);
        include $this->config['base_path'] . '/templates/auth/login.php';
    }

    public function login(array $params = []): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            $_SESSION['auth_error'] = 'Please enter email and password.';
            $this->redirect('/login');
            return;
        }

        // Limits are stored server-side per email and per IP, so clearing cookies does not reset them.
        $limiter = $this->rateLimiter();
        [$emailMax, $emailWindow] = $this->limit('login_email');
        [$ipMax, $ipWindow] = $this->limit('login_ip');
        $emailBucket = 'login:email:' . $email;
        $ipBucket = 'login:ip:' . $this->clientIp();
        if ($limiter->tooManyAttempts($emailBucket, $emailMax) || $limiter->tooManyAttempts($ipBucket, $ipMax)) {
            Logger::warning('Login rate limited', ['ip' => $this->clientIp()]);
            $_SESSION['auth_error'] = 'Too many login attempts. Please wait 15 minutes and try again, or reset your password.';
            $this->redirect('/login');
            return;
        }

        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('SELECT id, password_hash, session_version FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $valid = password_verify($password, $user ? $user['password_hash'] : self::DUMMY_HASH);
        if (!$user || !$valid) {
            $limiter->hit($emailBucket, $emailWindow);
            $limiter->hit($ipBucket, $ipWindow);
            $_SESSION['auth_error'] = 'Invalid email or password.';
            $this->redirect('/login');
            return;
        }

        $limiter->clear($emailBucket);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        Auth::login((int) $user['id'], (int) $user['session_version']);
        Logger::info('User logged in', ['user_id' => (int) $user['id']]);
        $this->redirect('/');
    }

    public function signupForm(array $params = []): void
    {
        if (Auth::check($this->config)) {
            $this->redirect('/');
            return;
        }
        $error = $_SESSION['auth_error'] ?? null;
        unset($_SESSION['auth_error']);
        include $this->config['base_path'] . '/templates/auth/signup.php';
    }

    public function signup(array $params = []): void
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $name = trim((string) ($_POST['name'] ?? ''));
        $contact = (string) ($_POST['contact_preference'] ?? 'email');
        if (!in_array($contact, ['email', 'phone', 'both'], true)) {
            $contact = 'email';
        }
        $errors = [];
        if ($email === '') {
            $errors[] = 'Email is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (strlen($password) > 72) {
            $errors[] = 'Password must be 72 characters or fewer.';
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($errors !== []) {
            $_SESSION['auth_error'] = implode(' ', $errors);
            $this->redirect('/signup');
            return;
        }
        [$max, $window] = $this->limit('signup_ip');
        if (!$this->rateLimiter()->attempt('signup:ip:' . $this->clientIp(), $max, $window)) {
            $_SESSION['auth_error'] = 'Too many sign-up attempts. Please try again later.';
            $this->redirect('/signup');
            return;
        }

        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('SELECT id, email, name FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            // Same response as a new signup, so the form cannot be used to discover registered emails.
            // The real owner is told by email instead.
            $this->mailer()->send($existing['email'], 'account_exists', ['name' => $existing['name']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, contact_preference) VALUES (?, ?, ?, ?)');
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name, $contact]);
            $userId = (int) $pdo->lastInsertId();
            $this->sendVerificationEmail(['id' => $userId, 'email' => $email, 'name' => $name]);
            Logger::info('User signed up', ['user_id' => $userId]);
        }
        $_SESSION['auth_notice'] = 'Thanks! We have sent an email to ' . $email . '. Click the link in it to verify your address, then log in.';
        $this->redirect('/login');
    }

    public function logout(array $params = []): void
    {
        Auth::logout();
        $this->redirect('/');
    }
}
