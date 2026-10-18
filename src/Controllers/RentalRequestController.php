<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Booking\BookingException;
use App\Booking\BookingStatus;
use App\Booking\Money;
use App\Controller;

class RentalRequestController extends Controller
{
    public function create(array $params): void
    {
        $user = Auth::user($this->config);
        $listingId = (int) ($params['id'] ?? 0);
        $back = $this->listingUrl($listingId);
        try {
            // Only dates and a message come from the browser; price and total are always calculated on the server.
            $requestId = $this->bookings()->requestRental(
                (int) $user['id'],
                $listingId,
                trim((string) ($_POST['start_date'] ?? '')),
                trim((string) ($_POST['end_date'] ?? '')),
                trim((string) ($_POST['message'] ?? '')) ?: null
            );
        } catch (BookingException $e) {
            $_SESSION['request_error'] = $e->getMessage();
            $this->redirect($back);
            return;
        }
        $rental = $this->bookings()->findForUser($requestId, (int) $user['id']);
        $_SESSION['rental_success'] = sprintf(
            'Request sent: %d day%s, %s in total. The owner will respond shortly.',
            $rental['days'],
            $rental['days'] === 1 ? '' : 's',
            Money::format((int) $rental['total_pence'])
        );
        $this->redirect('/rentals/' . $requestId);
    }

    public function myRequests(array $params = []): void
    {
        $user = Auth::user($this->config);
        $this->bookings()->expireOverdue();
        $requests = $this->bookings()->listForRenter((int) $user['id']);
        $success = $_SESSION['request_success'] ?? null;
        $error = $_SESSION['request_error'] ?? null;
        unset($_SESSION['request_success'], $_SESSION['request_error']);
        include $this->config['base_path'] . '/templates/requests/my.php';
    }

    /** Shared booking page for the renter and the owner: details, status, actions and history. */
    public function show(array $params): void
    {
        $user = Auth::user($this->config);
        $this->bookings()->expireOverdue();
        $rental = $this->bookings()->findForUser((int) ($params['id'] ?? 0), (int) $user['id']);
        if ($rental === null) {
            // Same response for "does not exist" and "not yours".
            http_response_code(404);
            $currentUser = $user;
            include $this->config['base_path'] . '/templates/404.php';
            return;
        }
        $timeline = $this->bookings()->timeline((int) $rental['id']);
        $payments = $this->payments();
        $paymentsEnabled = $payments->enabled();
        $split = $payments->split((int) $rental['total_pence']);
        $money = $rental['booking_id'] !== null ? $payments->summary((int) $rental['booking_id']) : ['payment' => null, 'refunds' => [], 'payout' => null];
        $paymentReturn = in_array($_GET['payment'] ?? '', ['success', 'cancelled'], true) ? $_GET['payment'] : null;
        $feePercent = rtrim(rtrim(number_format($this->config['payments']['platform_fee_bps'] / 100, 2), '0'), '.');
        $feePayer = $this->config['payments']['fee_payer'];
        $actions = $rental['booking_id'] !== null ? BookingStatus::actionsFor($rental['booking_status'], $rental['role']) : [];
        $success = $_SESSION['rental_success'] ?? null;
        $error = $_SESSION['rental_error'] ?? null;
        unset($_SESSION['rental_success'], $_SESSION['rental_error']);
        include $this->config['base_path'] . '/templates/rentals/show.php';
    }

    public function accept(array $params): void
    {
        $user = Auth::user($this->config);
        try {
            $this->bookings()->approve((int) $user['id'], (int) ($params['id'] ?? 0));
            $_SESSION['request_success'] = 'Request approved. It is awaiting renter payment before confirmation.';
        } catch (BookingException $e) {
            $_SESSION['request_error'] = $e->getMessage();
        }
        $this->redirect('/dashboard');
    }

    public function decline(array $params): void
    {
        $user = Auth::user($this->config);
        try {
            $this->bookings()->decline((int) $user['id'], (int) ($params['id'] ?? 0));
            $_SESSION['request_success'] = 'Request declined.';
        } catch (BookingException $e) {
            $_SESSION['request_error'] = $e->getMessage();
        }
        $this->redirect('/dashboard');
    }

    /** Renter withdraws a request before the owner answers. */
    public function cancelRequest(array $params): void
    {
        $user = Auth::user($this->config);
        $id = (int) ($params['id'] ?? 0);
        try {
            $this->bookings()->cancelRequest((int) $user['id'], $id);
            $_SESSION['rental_success'] = 'Request cancelled.';
        } catch (BookingException $e) {
            $_SESSION['rental_error'] = $e->getMessage();
        }
        $this->redirect('/rentals/' . $id);
    }

    /** Booking lifecycle actions (cancel, handover, return...). Allowed moves are defined in BookingStatus. */
    public function transition(array $params): void
    {
        $user = Auth::user($this->config);
        $id = (int) ($params['id'] ?? 0);
        $to = (string) ($_POST['to'] ?? '');
        try {
            $this->bookings()->transition((int) $user['id'], $id, $to);
            $_SESSION['rental_success'] = 'Booking updated: ' . BookingStatus::label($to) . '.';
            if ($to === BookingStatus::COMPLETED && $this->payments()->enabled()) {
                // Rental finished: pay the owner their share (or hold it until their payout account is ready).
                $rental = $this->bookings()->findForUser($id, (int) $user['id']);
                $this->payments()->releasePayout((int) $rental['booking_id']);
            }
        } catch (BookingException $e) {
            $_SESSION['rental_error'] = $e->getMessage();
        }
        $this->redirect('/rentals/' . $id);
    }

    private function listingUrl(int $listingId): string
    {
        $stmt = \App\Database::get($this->config)->prepare('SELECT slug FROM listings WHERE id = ?');
        $stmt->execute([$listingId]);
        $slug = $stmt->fetchColumn();
        return $slug === false ? '/listings' : '/listings/' . $slug;
    }
}
