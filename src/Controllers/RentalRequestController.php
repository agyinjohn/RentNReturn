<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Controller;
use App\Database;

class RentalRequestController extends Controller
{
    public function create(array $params): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $listingId = (int) ($params['id'] ?? 0);
        $startDate = trim((string) ($_POST['start_date'] ?? ''));
        $endDate = trim((string) ($_POST['end_date'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        $pdo = Database::get($this->config);

        $stmt = $pdo->prepare('SELECT id, user_id, slug, available_now, available_from, available_until FROM listings WHERE id = ? AND published = 1');
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch();
        if (!$listing) {
            $_SESSION['request_error'] = 'Listing not found.';
            $this->redirect('/listings');
            return;
        }
        $listingUrl = '/listings/' . $listing['slug'];
        if ((int) $listing['user_id'] === (int) $user['id']) {
            $_SESSION['request_error'] = 'You cannot request to rent your own listing.';
            $this->redirect($listingUrl);
            return;
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        $today = new \DateTimeImmutable('today');
        if (!$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate || $start > $end) {
            $_SESSION['request_error'] = 'Invalid date range.';
            $this->redirect($listingUrl);
            return;
        }
        if ($start < $today) {
            $_SESSION['request_error'] = 'Rental start date cannot be in the past.';
            $this->redirect($listingUrl);
            return;
        }
        if (!empty($listing['available_from']) && $startDate < $listing['available_from']) {
            $_SESSION['request_error'] = 'This item is not available that early.';
            $this->redirect($listingUrl);
            return;
        }
        if (!empty($listing['available_until']) && $endDate > $listing['available_until']) {
            $_SESSION['request_error'] = 'This item is not available that late.';
            $this->redirect($listingUrl);
            return;
        }

        $conflict = $pdo->prepare("SELECT id FROM bookings WHERE listing_id = ? AND status IN ('approved_awaiting_payment','confirmed','handover_ready','in_rental','return_pending') AND start_date <= ? AND end_date >= ? LIMIT 1");
        $conflict->execute([$listingId, $endDate, $startDate]);
        if ($conflict->fetch()) {
            $_SESSION['request_error'] = 'Those dates are no longer available.';
            $this->redirect($listingUrl);
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO rental_requests (listing_id, renter_id, start_date, end_date, message, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$listingId, $user['id'], $startDate, $endDate, $message ?: null, 'pending']);
        $_SESSION['request_success'] = 'Request sent. The owner will respond shortly.';
        $this->redirect($listingUrl);
    }

    public function myRequests(array $params = []): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('
            SELECT r.*, l.title AS listing_title, l.slug AS listing_slug, u.name AS owner_name
            FROM rental_requests r
            JOIN listings l ON l.id = r.listing_id
            JOIN users u ON u.id = l.user_id
            WHERE r.renter_id = ?
            ORDER BY r.created_at DESC
        ');
        $stmt->execute([$user['id']]);
        $requests = $stmt->fetchAll();
        $success = $_SESSION['request_success'] ?? null;
        $error = $_SESSION['request_error'] ?? null;
        unset($_SESSION['request_success'], $_SESSION['request_error']);
        include $this->config['base_path'] . '/templates/requests/my.php';
    }

    public function accept(array $params): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $requestId = (int) ($params['id'] ?? 0);
        $pdo = Database::get($this->config);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT r.id, r.listing_id, r.renter_id, r.start_date, r.end_date FROM rental_requests r JOIN listings l ON l.id = r.listing_id WHERE r.id = ? AND l.user_id = ? AND r.status = ? FOR UPDATE');
            $stmt->execute([$requestId, $user['id'], 'pending']);
            $req = $stmt->fetch();
            if (!$req) {
                throw new \RuntimeException('Request not found or already responded.');
            }

            // Lock the listing so concurrent accepts for the same item serialize.
            $lock = $pdo->prepare('SELECT id, published, available_from, available_until FROM listings WHERE id = ? AND user_id = ? FOR UPDATE');
            $lock->execute([$req['listing_id'], $user['id']]);
            $listing = $lock->fetch();
            if (!$listing || !(int) $listing['published']) {
                throw new \RuntimeException('Listing is no longer available.');
            }
            if ((!empty($listing['available_from']) && $req['start_date'] < $listing['available_from']) || (!empty($listing['available_until']) && $req['end_date'] > $listing['available_until'])) {
                throw new \RuntimeException('Requested dates are outside the listing availability window.');
            }

            $conflict = $pdo->prepare("SELECT id FROM bookings WHERE listing_id = ? AND status IN ('approved_awaiting_payment','confirmed','handover_ready','in_rental','return_pending') AND start_date <= ? AND end_date >= ? LIMIT 1 FOR UPDATE");
            $conflict->execute([$req['listing_id'], $req['end_date'], $req['start_date']]);
            if ($conflict->fetch()) {
                throw new \RuntimeException('Those dates have already been reserved by another booking.');
            }

            $pdo->prepare('UPDATE rental_requests SET status = ?, responded_at = NOW() WHERE id = ?')->execute(['accepted', $requestId]);
            $pdo->prepare('INSERT INTO bookings (rental_request_id, listing_id, renter_id, owner_id, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$requestId, $req['listing_id'], $req['renter_id'], $user['id'], $req['start_date'], $req['end_date'], 'approved_awaiting_payment']);
            $pdo->commit();
            $_SESSION['request_success'] = 'Request approved. It is awaiting renter payment before confirmation.';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['request_error'] = $e instanceof \RuntimeException ? $e->getMessage() : 'Could not accept request.';
        }
        $this->redirect('/dashboard');
    }

    public function decline(array $params): void
    {
        $user = Auth::user($this->config);
        if (!$user) {
            $this->redirect('/login');
            return;
        }
        $requestId = (int) ($params['id'] ?? 0);
        $pdo = Database::get($this->config);
        $stmt = $pdo->prepare('
            SELECT r.id FROM rental_requests r
            JOIN listings l ON l.id = r.listing_id
            WHERE r.id = ? AND l.user_id = ? AND r.status = ?
        ');
        $stmt->execute([$requestId, $user['id'], 'pending']);
        if (!$stmt->fetch()) {
            $_SESSION['request_error'] = 'Request not found or already responded.';
            $this->redirect('/dashboard');
            return;
        }
        $pdo->prepare('UPDATE rental_requests SET status = ?, responded_at = NOW() WHERE id = ?')->execute(['declined', $requestId]);
        $_SESSION['request_success'] = 'Request declined.';
        $this->redirect('/dashboard');
    }
}
