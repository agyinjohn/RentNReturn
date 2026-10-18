<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;

class AuthController extends Controller
{
    public function loginForm(array $params = []): void
    {
        if (Auth::check($this->config)) {
            $this->redirect('/');
            return;
        }
        $error = $_SESSION['auth_error'] ?? null;
        unset($_SESSION['auth_error']);
        include $this->config['base_path'] . '/templates/auth/login.php';
    }

    public function login(array $params = []): void
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            $_SESSION['auth_error'] = 'Please enter email and password.';
            $this->redirect('/login');
            return;
        }
        $now = time();
        $attempts = $_SESSION['login_attempts'] ?? ['count' => 0, 'first_at' => $now];
        if (($now - (int) $attempts['first_at']) > 900) {
            $attempts = ['count' => 0, 'first_at' => $now];
        }
        if ((int) $attempts['count'] >= 8) {
            $_SESSION['auth_error'] = 'Too many login attempts. Please wait and try again.';
            $this->redirect('/login');
            return;
        }

        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $attempts['count'] = (int) $attempts['count'] + 1;
            $_SESSION['login_attempts'] = $attempts;
            $_SESSION['auth_error'] = 'Invalid email or password.';
            $this->redirect('/login');
            return;
        }
        unset($_SESSION['login_attempts']);
        Auth::login((int) $user['id']);
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
        $email = trim((string) ($_POST['email'] ?? ''));
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
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($errors !== []) {
            $_SESSION['auth_error'] = implode(' ', $errors);
            $this->redirect('/signup');
            return;
        }
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $_SESSION['auth_error'] = 'An account with this email already exists.';
            $this->redirect('/signup');
            return;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, contact_preference) VALUES (?, ?, ?, ?)');
        $stmt->execute([$email, $hash, $name, $contact]);
        Auth::login((int) $pdo->lastInsertId());
        $this->redirect('/');
    }

    public function logout(array $params = []): void
    {
        Auth::logout();
        $this->redirect('/');
    }
}
