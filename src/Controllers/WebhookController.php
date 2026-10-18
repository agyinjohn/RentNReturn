<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controller;
use App\Database;
use App\Payments\WebhookProcessor;

class WebhookController extends Controller
{
    public function stripe(array $params = []): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        $secret = $this->config['stripe']['webhook_secret'];
        if ($secret === '' || $this->config['stripe']['secret_key'] === '') {
            http_response_code(503);
            echo 'payments not configured';
            return;
        }
        $processor = new WebhookProcessor(
            Database::get($this->config),
            $secret,
            (bool) preg_match('/^(sk|rk)_live_/', $this->config['stripe']['secret_key']),
            $this->payments(),
            $this->connect()
        );
        [$status, $body] = $processor->handle((string) file_get_contents('php://input'), (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
        http_response_code($status);
        echo $body;
    }
}
