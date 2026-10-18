<?php

declare(strict_types=1);

/*
 * Restore a backup made by bin/backup.php into the CONFIGURED database and uploads folder:
 *   php bin/restore.php storage/backups/<timestamp> --yes
 * This OVERWRITES the current database tables and listing photos. Check APP_ENV/DB_DATABASE first.
 * Needs the mysql (or mariadb) client on PATH, or MYSQL_CLIENT_PATH set.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$db = $config['database'];
$dir = rtrim((string) ($argv[1] ?? ''), '/');

if ($dir === '' || !is_file($dir . '/manifest.json')) {
    fwrite(STDERR, "Usage: php bin/restore.php <backup-dir> --yes\n");
    exit(1);
}
$manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
foreach ($manifest['files'] as $name => $sha) {
    if (!is_file($dir . '/' . $name) || hash_file('sha256', $dir . '/' . $name) !== $sha) {
        fwrite(STDERR, "Checksum mismatch or missing file: {$name}. Backup may be corrupt — not restoring.\n");
        exit(1);
    }
}

echo "Backup from {$manifest['created_at']} ({$manifest['app_env']}, database {$manifest['database']}, {$manifest['photos']} photos)", PHP_EOL;
echo "Will restore INTO: APP_ENV={$config['env']} database {$db['database']} on {$db['host']}", PHP_EOL;
if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "This overwrites that database and the uploads folder. Re-run with --yes to continue.\n");
    exit(1);
}

$clientBinary = getenv('MYSQL_CLIENT_PATH') ?: (trim((string) shell_exec('command -v mysql 2>/dev/null')) ?: 'mariadb');
$cmd = [$clientBinary, '--default-character-set=utf8mb4', '-h', $db['host'], '-P', (string) $db['port'], '-u', $db['username'], $db['database']];
$env = getenv();
$env['MYSQL_PWD'] = $db['password'];

$proc = proc_open($cmd, [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes, null, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not run {$clientBinary}\n");
    exit(1);
}
$gz = gzopen($dir . '/database.sql.gz', 'rb');
while (!gzeof($gz)) {
    fwrite($pipes[0], (string) gzread($gz, 1 << 16));
}
gzclose($gz);
fclose($pipes[0]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
if (proc_close($proc) !== 0) {
    fwrite(STDERR, "Database restore failed: {$stderr}\n");
    exit(1);
}
echo 'Database restored.', PHP_EOL;

$uploadsDir = $config['upload']['listing_images_path'];
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
$zip = new ZipArchive();
if ($zip->open($dir . '/uploads.zip') !== true) {
    fwrite(STDERR, "Cannot open uploads.zip\n");
    exit(1);
}
$restored = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    // Only plain filenames from our own backups; refuse anything that could escape the folder.
    if ($name === '.empty' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        continue;
    }
    file_put_contents($uploadsDir . '/' . $name, $zip->getFromIndex($i));
    $restored++;
}
$zip->close();

App\Logger::info('Backup restored', ['dir' => $dir, 'photos' => $restored]);
echo "Uploads restored ({$restored} photos).", PHP_EOL;
