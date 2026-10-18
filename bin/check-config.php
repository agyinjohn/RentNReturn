<?php

declare(strict_types=1);

/*
 * Deployment check: php bin/check-config.php
 * Fails (exit code 1) if the environment is unsafe for its APP_ENV — e.g. debug on or http:// APP_URL in
 * production — and checks the database is reachable and fully migrated.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// bootstrap.php throws (and exits 1 with the reason) if ConfigValidator finds a problem.
$config = require dirname(__DIR__) . '/bootstrap.php';
echo "APP_ENV={$config['env']} APP_URL={$config['url']} debug=" . ($config['debug'] ? 'on' : 'off') . " mail={$config['mail']['driver']}", PHP_EOL;

$pending = array_keys(array_filter(
    (new App\Migrator(App\Database::get($config), $config['storage_path']))->status(),
    static fn (bool $applied): bool => !$applied
));
if ($pending !== []) {
    fwrite(STDERR, 'Pending migrations: ' . implode(', ', $pending) . ' — run php bin/migrate.php' . PHP_EOL);
    exit(1);
}

foreach ([$config['log']['path'], $config['upload']['listing_images_path'], $config['upload']['draft_images_path'], $config['upload']['tmp_path']] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        fwrite(STDERR, "Cannot create directory {$dir}" . PHP_EOL);
        exit(1);
    }
    if (!is_writable($dir)) {
        fwrite(STDERR, "Directory not writable by this user: {$dir}" . PHP_EOL);
        exit(1);
    }
}

echo 'OK: configuration is safe, database is reachable and up to date, storage is writable.', PHP_EOL;
