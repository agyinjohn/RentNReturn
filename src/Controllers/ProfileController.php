<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Verification\IdentityVerification;
use App\Verification\PhoneNumber;
use App\Verification\TrustBadge;

class ProfileController extends Controller
{
    public function edit(array $params = []): void
    {
        $user = Auth::user($this->config);
        $identity = new IdentityVerification(Database::get($this->config), IdentityVerification::providerFromConfig($this->config));
        $identityCheck = $identity->current((int) $user['id']);
        $identityAvailable = $identity->provider()->available();
        $badge = TrustBadge::earned($user);
        $publicName = TrustBadge::publicName($user);
        $maskedPhone = !empty($user['phone']) ? PhoneNumber::mask($user['phone']) : null;
        $success = $_SESSION['profile_success'] ?? null;
        $error = $_SESSION['profile_error'] ?? null;
        unset($_SESSION['profile_success'], $_SESSION['profile_error']);
        include $this->config['base_path'] . '/templates/profile/edit.php';
    }

    /**
     * Only name, display name and contact preference can be changed here. Verification fields are
     * deliberately not read from the form, so they cannot be set by editing the request.
     */
    public function update(array $params = []): void
    {
        $user = Auth::user($this->config);
        $name = trim((string) ($_POST['name'] ?? ''));
        $displayName = trim(preg_replace('/\s+/', ' ', (string) ($_POST['display_name'] ?? '')));
        $contact = (string) ($_POST['contact_preference'] ?? 'email');
        if (!in_array($contact, ['email', 'phone', 'both'], true)) {
            $contact = 'email';
        }
        $errors = [];
        if ($name === '') {
            $errors[] = 'Please enter your name.';
        }
        if ($displayName !== '' && ($problem = self::displayNameProblem($displayName)) !== null) {
            $errors[] = $problem;
        }
        if ($errors !== []) {
            $_SESSION['profile_error'] = implode(' ', $errors);
            $this->redirect('/profile');
            return;
        }
        Database::get($this->config)
            ->prepare('UPDATE users SET name = ?, display_name = ?, contact_preference = ? WHERE id = ?')
            ->execute([$name, $displayName !== '' ? $displayName : null, $contact, $user['id']]);
        $_SESSION['profile_success'] = 'Profile updated.';
        $this->redirect('/profile');
    }

    /** Public names must not carry contact details (keeps payments and messages on the platform). */
    public static function displayNameProblem(string $name): ?string
    {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return 'Display name must be 2–40 characters.';
        }
        if (!preg_match("/^[\\p{L}\\p{N} .'\\-]+$/u", $name)) {
            return 'Display name can contain letters, numbers, spaces, apostrophes, dots and hyphens.';
        }
        if (preg_match('/\d{5,}/', preg_replace('/[\s.\-]/', '', $name)) || preg_match('/(https?|www|\.com|\.co\.uk)/i', $name)) {
            return 'Please do not put phone numbers or web addresses in your display name.';
        }
        if (stripos(str_replace(' ', '', $name), 'rentnreturn') !== false) {
            return 'Display names cannot include "RentNReturn".';
        }
        return null;
    }
}
