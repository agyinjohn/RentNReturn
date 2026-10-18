<?php

declare(strict_types=1);

// Test helper: confirm a booking's payment from a separate process, starting at a given moment,
// so several processes race each other. Usage: php confirm_worker.php <bookingId> <startAtMicrotime>
require dirname(__DIR__) . '/vendor/autoload.php';

[$bookingId, $startAt] = [(int) $argv[1], (float) $argv[2]];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('TEST_DB_HOST'), (int) getenv('TEST_DB_PORT'), getenv('TEST_DB_NAME')),
    getenv('TEST_DB_USERNAME'),
    getenv('TEST_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec("SET time_zone = '+00:00'");
$config = require dirname(__DIR__) . '/config/app.php';
$service = new App\Booking\BookingService($pdo, $config['bookings']);

while (microtime(true) < $startAt) {
    usleep(500);
}
echo $service->confirmPayment($bookingId, 'pi_race');
