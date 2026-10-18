<?php

declare(strict_types=1);

/*
 * Back up the database and uploaded listing photos:
 *   php bin/backup.php [--keep=14]
 * Writes storage/backups/<timestamp>/{database.sql.gz, uploads.zip, manifest.json} (or BACKUP_PATH/...).
 * --keep=N deletes all but the newest N backups. Restore with bin/restore.php — see docs/OPERATIONS.md.
 * Needs the mysqldump (or mariadb-dump) client on PATH, or MYSQLDUMP_PATH set.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$db = $config['database'];
$root = rtrim(getenv('BACKUP_PATH') ?: $config['storage_path'] . '/backups', '/');
$dir = $root . '/' . gmdate('Ymd-His');
if (!mkdir($dir, 0750, true)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}

$dumpBinary = getenv('MYSQLDUMP_PATH') ?: (trim((string) shell_exec('command -v mysqldump 2>/dev/null')) ?: 'mariadb-dump');
$cmd = [$dumpBinary, '--single-transaction', '--routines', '--triggers', '--no-tablespaces',
    '--default-character-set=utf8mb4', '-h', $db['host'], '-P', (string) $db['port'], '-u', $db['username'], $db['database']];
// Password via environment, never on the command line (visible to other users in the process list).
$env = getenv();
$env['MYSQL_PWD'] = $db['password'];

$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not run {$dumpBinary}\n");
    exit(1);
}
$gz = gzopen($dir . '/database.sql.gz', 'wb6');
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 1 << 16);
    if ($chunk !== false && $chunk !== '') {
        gzwrite($gz, $chunk);
    }
}
gzclose($gz);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    fwrite(STDERR, "Database dump failed: {$stderr}\n");
    exit(1);
}

$uploadsDir = $config['upload']['listing_images_path'];
$zip = new ZipArchive();
$zip->open($dir . '/uploads.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$photoCount = 0;
if (is_dir($uploadsDir)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($uploadsDir) + 1));
            $photoCount++;
        }
    }
}
if ($photoCount === 0) {
    $zip->addFromString('.empty', '');
}
$zip->close();

$manifest = [
    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'app_env' => $config['env'],
    'database' => $db['database'],
    'git_commit' => trim((string) @shell_exec('git -C ' . escapeshellarg($config['base_path']) . ' rev-parse HEAD 2>/dev/null')) ?: null,
    'photos' => $photoCount,
    'files' => [
        'database.sql.gz' => hash_file('sha256', $dir . '/database.sql.gz'),
        'uploads.zip' => hash_file('sha256', $dir . '/uploads.zip'),
    ],
];
file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

foreach ($argv as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m) && (int) $m[1] > 0) {
        $all = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        rsort($all, SORT_STRING);
        foreach (array_slice($all, (int) $m[1]) as $old) {
            array_map('unlink', glob($old . '/*') ?: []);
            rmdir($old);
            echo "Pruned {$old}", PHP_EOL;
        }
    }
}

App\Logger::info('Backup created', ['dir' => $dir, 'photos' => $photoCount]);
echo "Backup written to {$dir} ({$photoCount} photos)", PHP_EOL;
