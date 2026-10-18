<?php

declare(strict_types=1);

/*
 * Housekeeping — run hourly from cron / Task Scheduler:
 *   php bin/cleanup.php
 * Removes expired rate-limit counters and old tokens, abandoned listing draft photos (older than 24h)
 * and leftover temporary AI upload files (older than 1h). Releases approved bookings whose payment
 * deadline passed and expires requests the owner never answered before their start date.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/bootstrap.php';
$pdo = App\Database::get($config);

$removeOlderThan = static function (string $dir, int $seconds): int {
    $removed = 0;
    foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $file) {
        if (is_file($file) && basename($file) !== '.htaccess' && filemtime($file) < time() - $seconds && @unlink($file)) {
            $removed++;
        }
    }
    return $removed;
};

$counts = [
    'rate_limits' => (new App\RateLimiter($pdo))->purgeExpired(),
    'user_tokens' => (new App\UserTokens($pdo))->purgeOld(),
    'draft_images' => $removeOlderThan($config['upload']['draft_images_path'], 86400),
    'tmp_files' => $removeOlderThan($config['upload']['tmp_path'], 3600),
];
$expired = (new App\Booking\BookingService($pdo, $config['bookings']))->expireOverdue();
$counts['expired_bookings'] = $expired['bookings'];
$counts['expired_requests'] = $expired['requests'];

App\Logger::info('Cleanup completed', $counts);
foreach ($counts as $what => $n) {
    echo "{$what}: {$n}", PHP_EOL;
}
