<?php

declare(strict_types=1);

/*
 * Apply database migrations.
 *   php bin/migrate.php           create the database if needed, then apply pending migrations
 *   php bin/migrate.php --status  list migrations and whether each has been applied
 *
 * Always back up the database first (php bin/backup.php) and run on staging before production.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$db = $config['database'];

// Create the database itself if it does not exist yet (fresh development/staging installs).
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], $db['port'], $db['charset']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $db['database']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$server = null;

$migrator = new App\Migrator(App\Database::get($config), $config['storage_path'], static function (string $line): void {
    echo $line, PHP_EOL;
});

if (in_array('--status', $argv, true)) {
    foreach ($migrator->status() as $version => $applied) {
        printf("%-45s %s\n", $version, $applied ? 'applied' : 'PENDING');
    }
    exit(0);
}

$ran = $migrator->migrate();
echo $ran === [] ? 'Nothing to migrate; database is up to date.' . PHP_EOL : 'Done: ' . implode(', ', $ran) . PHP_EOL;
