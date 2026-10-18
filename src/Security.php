<?php

declare(strict_types=1);

namespace App;

final class Security
{
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validateCsrf(): bool
    {
        $provided = (string) ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return $provided !== '' && hash_equals(self::csrfToken(), $provided);
    }

    public static function detectImageType(string $path): ?string
    {
        if (!is_file($path) || @getimagesize($path) === false) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: '';
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : null;
    }

    public static function imageExtension(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
    }
}
