<?php

declare(strict_types=1);

/*
 * Build a clean source package to share with developers or deploy:
 *   php bin/package.php
 * Creates build/rentnreturn-<commit>.zip from the committed code only (git archive), so local .env files,
 * .git, .claude worktrees, uploads, logs, backups, vendor/ and IDE files can never be included.
 * The package is then scanned and rejected if it contains anything that looks like a secret.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$run = static function (string $cmd) use ($root): string {
    $out = [];
    exec('cd ' . escapeshellarg($root) . ' && ' . $cmd . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Command failed: {$cmd}\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    return implode("\n", $out);
};

if (trim($run('git status --porcelain')) !== '') {
    fwrite(STDERR, "Warning: uncommitted changes are NOT included — the package is built from the last commit.\n");
}
$commit = trim($run('git rev-parse --short HEAD'));
@mkdir($root . '/build', 0755);
$zipPath = $root . "/build/rentnreturn-{$commit}.zip";
$run('git archive --format=zip --prefix=rentnreturn/ -o ' . escapeshellarg($zipPath) . ' HEAD');

// Paths that must never be in a package.
$forbiddenPaths = [
    '#(^|/)\.env$#', '#(^|/)\.env\.(?!example$)[^/]+$#', '#(^|/)\.git/#', '#(^|/)\.claude/#', '#(^|/)\.idea/#', '#(^|/)\.vscode/#',
    '#/public/uploads/.+#', '#/storage/(logs|backups|tmp|uploads)/.+#', '#/vendor/#', '#\.log$#', '#\.sql\.gz$#',
];
// Content that looks like a real credential.
$secretPatterns = [
    'Stripe live/test secret key' => '/\b[sr]k_(live|test)_[A-Za-z0-9]{10,}/',
    'Stripe webhook secret' => '/\bwhsec_[A-Za-z0-9]{10,}/',
    'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
    'Google API key' => '/\bAIza[0-9A-Za-z_\-]{30,}/',
    'OpenAI key' => '/\bsk-(proj-)?[A-Za-z0-9_\-]{20,}/',
    'Groq key' => '/\bgsk_[A-Za-z0-9]{20,}/',
    'Private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
    'Assigned secret in env file' => '/^(DB_PASSWORD|MAIL_PASSWORD|[A-Z_]*API_KEY|[A-Z_]*SECRET[A-Z_]*)=\S+/m',
];

$zip = new ZipArchive();
$zip->open($zipPath);
$problems = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    foreach ($forbiddenPaths as $pattern) {
        if (preg_match($pattern, $name)) {
            $problems[] = "forbidden path: {$name}";
        }
    }
    if (str_ends_with($name, '/') || preg_match('/\.(png|jpe?g|webp|gif|svg|ico|zip)$/i', $name)) {
        continue;
    }
    $content = (string) $zip->getFromIndex($i);
    foreach ($secretPatterns as $label => $pattern) {
        if (preg_match($pattern, $content)) {
            $problems[] = "possible {$label} in {$name}";
        }
    }
}
$count = $zip->numFiles;
$zip->close();

if ($problems !== []) {
    unlink($zipPath);
    fwrite(STDERR, "Package REJECTED:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}
echo "Clean package: {$zipPath} ({$count} entries). Recipients run: composer install --no-dev", PHP_EOL;
