<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;

class DashboardController extends Controller
{
    public function index(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('
            SELECT r.*, l.title AS listing_title, l.slug AS listing_slug, u.name AS renter_name
            FROM rental_requests r
            JOIN listings l ON l.id = r.listing_id
            JOIN users u ON u.id = r.renter_id
            WHERE l.user_id = ?
            ORDER BY r.created_at DESC
        ');
        $stmt->execute([$user['id']]);
        $requests = $stmt->fetchAll();
        $stmt = $pdo->prepare('
            SELECT b.*, l.title AS listing_title, l.slug AS listing_slug, u.name AS renter_name
            FROM bookings b
            JOIN listings l ON l.id = b.listing_id
            JOIN users u ON u.id = b.renter_id
            WHERE b.owner_id = ?
            ORDER BY b.created_at DESC
        ');
        $stmt->execute([$user['id']]);
        $bookings = $stmt->fetchAll();
        $success = $_SESSION['request_success'] ?? null;
        $error = $_SESSION['request_error'] ?? null;
        unset($_SESSION['request_success'], $_SESSION['request_error']);
        include $this->config['base_path'] . '/templates/dashboard/index.php';
    }
}
