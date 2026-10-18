<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Booking\BookingException;
use App\Controller;
use App\Database;
use App\Logger;
use App\Payments\PaymentException;

class PaymentController extends Controller
{
    /** Renter → Stripe Checkout. The amount is taken from the booking on the server. */
    public function pay(array $params): void
    {
        $user = Auth::user($this->config);
        $id = (int) ($params['id'] ?? 0);
        try {
            $this->redirect($this->payments()->startCheckout((int) $user['id'], $id), 303);
        } catch (PaymentException $e) {
            $_SESSION['rental_error'] = $e->getMessage();
            $this->redirect('/rentals/' . $id);
        }
    }

    /** Owner cancels a paid booking before handover; the renter is refunded in full. */
    public function ownerCancel(array $params): void
    {
        $user = Auth::user($this->config);
        $id = (int) ($params['id'] ?? 0);
        try {
            $this->payments()->ownerCancelPaid((int) $user['id'], $id);
            $_SESSION['rental_success'] = 'Booking cancelled. The renter is being refunded in full.';
        } catch (BookingException | PaymentException $e) {
            $_SESSION['rental_error'] = $e->getMessage();
        }
        $this->redirect('/rentals/' . $id);
    }

    public function payouts(array $params = []): void
    {
        $user = Auth::user($this->config);
        $connect = $this->connect();
        $account = $connect?->account((int) $user['id']);
        $stmt = Database::get($this->config)->prepare('
            SELECT po.*, b.rental_request_id, b.start_date, b.end_date, b.status AS booking_status, l.title
            FROM payouts po JOIN bookings b ON b.id = po.booking_id JOIN listings l ON l.id = b.listing_id
            WHERE po.owner_id = ? ORDER BY po.id DESC
        ');
        $stmt->execute([$user['id']]);
        $payouts = $stmt->fetchAll();
        $paymentsEnabled = $connect !== null;
        $feeBps = $this->config['payments']['platform_fee_bps'];
        $feePayer = $this->config['payments']['fee_payer'];
        $success = $_SESSION['payout_success'] ?? null;
        $error = $_SESSION['payout_error'] ?? null;
        unset($_SESSION['payout_success'], $_SESSION['payout_error']);
        include $this->config['base_path'] . '/templates/payouts/index.php';
    }

    public function onboard(array $params = []): void
    {
        $this->toOnboarding();
    }

    /** Stripe sends the owner back here; their account status is re-read from Stripe (and kept fresh by webhooks). */
    public function onboardReturn(array $params = []): void
    {
        $user = Auth::user($this->config);
        $connect = $this->connect();
        try {
            $account = $connect?->refresh((int) $user['id']);
        } catch (\Throwable $e) {
            Logger::error('Could not refresh connected account', ['error' => $e->getMessage()]);
            $account = null;
        }
        if ($account && (int) $account['payouts_enabled']) {
            $this->payments()->releaseDuePayouts((int) $user['id']);
            $_SESSION['payout_success'] = 'Payouts are set up. Your earnings will be sent to your bank account.';
        } elseif ($account && (int) $account['details_submitted']) {
            $_SESSION['payout_success'] = 'Thanks — Stripe is checking your details. This usually takes a few minutes.';
        } else {
            $_SESSION['payout_error'] = 'Payout setup is not finished yet. You can continue where you left off.';
        }
        $this->redirect('/payouts');
    }

    /** Stripe onboarding link expired or was reused: make a fresh one. */
    public function onboardRefresh(array $params = []): void
    {
        $this->toOnboarding();
    }

    public function stripeDashboard(array $params = []): void
    {
        $user = Auth::user($this->config);
        try {
            $this->redirect($this->connect()?->dashboardUrl((int) $user['id']) ?? '/payouts', 303);
        } catch (\Throwable $e) {
            $_SESSION['payout_error'] = $e instanceof PaymentException ? $e->getMessage() : 'Could not open Stripe right now.';
            $this->redirect('/payouts');
        }
    }

    private function toOnboarding(): void
    {
        $user = Auth::user($this->config);
        $connect = $this->connect();
        if ($connect === null) {
            $_SESSION['payout_error'] = 'Online payments are not available yet.';
            $this->redirect('/payouts');
            return;
        }
        if (empty($user['email_verified_at'])) {
            $this->redirect('/verify-email');
            return;
        }
        try {
            $this->redirect($connect->onboardingUrl($user), 303);
        } catch (\Throwable $e) {
            Logger::error('Could not start Stripe onboarding', ['error' => $e->getMessage()]);
            $_SESSION['payout_error'] = 'We could not open Stripe just now. Please try again.';
            $this->redirect('/payouts');
        }
    }
}
