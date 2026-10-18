<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;

class HomeController extends Controller
{
    public function index(array $params = []): void
    {
        $user = Auth::user($this->config);
        $pdo = Database::get($this->config);
        $stmt = $pdo->query('
            SELECT l.id, l.slug, l.title, l.category, l.price_daily, l.available_now, l.published,
                   u.name AS owner_name,
                   (SELECT file_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS cover_image
            FROM listings l
            JOIN users u ON u.id = l.user_id
            WHERE l.published = 1
            ORDER BY l.updated_at DESC
            LIMIT 8
        ');
        $featured = $stmt->fetchAll();
        $categories = $this->config['categories'];
        include $this->config['base_path'] . '/templates/home.php';
    }
}
