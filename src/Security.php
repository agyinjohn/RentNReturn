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

    public static function sendHeaders(array $config): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self)');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
            . "script-src 'self'; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
        if (($config['env'] ?? 'development') !== 'development') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function isHttps(array $config): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        return !empty($config['trust_proxy_headers'])
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * Outside development, redirect plain-HTTP requests to the configured https:// APP_URL.
     * Uses APP_URL rather than the Host header so a forged Host cannot redirect users elsewhere.
     */
    public static function enforceHttps(array $config): void
    {
        if (($config['env'] ?? 'development') === 'development' || PHP_SAPI === 'cli' || self::isHttps($config)) {
            return;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $config['url'] . (str_starts_with($uri, '/') ? $uri : '/'), true, 301);
        exit;
    }

    public static function clientIp(array $config): string
    {
        if (!empty($config['trust_proxy_headers']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Detect the real image type from file contents. Returns null for anything that is not a
     * decodable JPEG/PNG/WebP within the configured dimension limits (also guards against
     * decompression bombs: tiny files that expand to enormous images).
     */
    public static function detectImageType(string $path, array $uploadConfig = []): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $size = @getimagesize($path);
        if ($size === false) {
            return null;
        }
        [$width, $height] = $size;
        $min = (int) ($uploadConfig['min_dimension'] ?? 1);
        $max = (int) ($uploadConfig['max_dimension'] ?? 10000);
        $maxPixels = (int) (($uploadConfig['max_megapixels'] ?? 50) * 1000000);
        if ($width < $min || $height < $min || $width > $max || $height > $max || $width * $height > $maxPixels) {
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
