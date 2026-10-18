<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;

class ProfileController extends Controller
{
    public function edit(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $success = $_SESSION['profile_success'] ?? null;
        unset($_SESSION['profile_success']);
        include $this->config['base_path'] . '/templates/profile/edit.php';
    }

    public function update(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $contact = (string) ($_POST['contact_preference'] ?? 'email');
        if (!in_array($contact, ['email', 'phone', 'both'], true)) {
            $contact = 'email';
        }
        if ($name === '') {
            $_SESSION['profile_success'] = false;
            $this->redirect('/profile');
            return;
        }
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('UPDATE users SET name = ?, contact_preference = ? WHERE id = ?');
        $stmt->execute([$name, $contact, $user['id']]);
        $_SESSION['profile_success'] = true;
        $this->redirect('/profile');
    }
}
