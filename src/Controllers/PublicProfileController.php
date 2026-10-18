<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;
use App\Verification\TrustBadge;

/** Public member page: display name, badge, member-since, marketplace history. Never legal name, contact or KYC details. */
class PublicProfileController extends Controller
{
    public function show(array $params): void
    {
        $currentUser = Auth::user($this->config);
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('
            SELECT u.id, ' . TrustBadge::publicNameSql('u') . ' AS public_name, ' . TrustBadge::sql('u') . ' AS verified,
                   u.email_verified_at IS NOT NULL AS email_verified, u.phone_verified_at IS NOT NULL AS phone_verified,
                   u.identity_verified_at IS NOT NULL AS identity_verified, u.created_at,
                   (SELECT COUNT(*) FROM bookings b WHERE b.owner_id = u.id AND b.status = \'completed\') AS completed_as_owner,
                   (SELECT COUNT(*) FROM bookings b WHERE b.renter_id = u.id AND b.status = \'completed\') AS completed_as_renter
            FROM users u WHERE u.id = ?
        ');
        $stmt->execute([(int) ($params['id'] ?? 0)]);
        $person = $stmt->fetch();
        if (!$person) {
            http_response_code(404);
            include $this->config['base_path'] . '/templates/404.php';
            return;
        }
        $stmt = $pdo->prepare('
            SELECT l.slug, l.title, l.price_daily_pence,
                   (SELECT file_path FROM listing_images WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS cover_image
            FROM listings l WHERE l.user_id = ? AND l.published = 1 ORDER BY l.updated_at DESC LIMIT 12
        ');
        $stmt->execute([$person['id']]);
        $listings = $stmt->fetchAll();
        include $this->config['base_path'] . '/templates/people/show.php';
    }
}
