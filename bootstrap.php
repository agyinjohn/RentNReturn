<?php

declare(strict_types=1);

// Optional: load .env into getenv (no extra package required)
if (is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            $v = trim($m[2]);
            if (preg_match('/^["\'](.+)["\']$/s', $v, $q)) $v = $q[1];
            putenv(trim($m[1]) . '=' . $v);
        }
    }
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        if (strpos($class, 'App\\') !== 0) {
            return;
        }
        $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

$config = require __DIR__ . '/config/app.php';
$config['base_path'] = __DIR__;
$config['categories'] = require __DIR__ . '/config/categories.php';
$config['conditions'] = require __DIR__ . '/config/conditions.php';

return $config;
