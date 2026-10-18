<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;

class DashboardController extends Controller
{
    public function index(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $this->bookings()->expireOverdue();
        $rentals = $this->bookings()->listForOwner((int) $user['id']);
        $success = $_SESSION['request_success'] ?? null;
        $error = $_SESSION['request_error'] ?? null;
        unset($_SESSION['request_success'], $_SESSION['request_error']);
        include $this->config['base_path'] . '/templates/dashboard/index.php';
    }
}
