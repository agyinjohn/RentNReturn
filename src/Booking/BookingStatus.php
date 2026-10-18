<?php

declare(strict_types=1);

namespace App\Booking;

/**
 * Booking lifecycle. A rental starts as a request; when the owner approves it a booking is created:
 *
 *   requested ──approve──▶ approved_awaiting_payment ──payment (Stripe only)──▶ confirmed
 *       │                        │            │                                 │
 *    decline/cancel/expire    cancel       expire (payment deadline)     handover_ready (optional)
 *                                                                               │
 *                                                       in_rental ◀─────────────┘
 *                                                           │
 *                                                   return_pending ──▶ completed
 *
 * refunded / disputed are reserved for Steps 6–7 (refunds, disputes) and have no transitions yet.
 */
final class BookingStatus
{
    public const AWAITING_PAYMENT = 'approved_awaiting_payment';
    public const CONFIRMED = 'confirmed';
    public const HANDOVER_READY = 'handover_ready';
    public const IN_RENTAL = 'in_rental';
    public const RETURN_PENDING = 'return_pending';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const REFUNDED = 'refunded';
    public const DISPUTED = 'disputed';

    /** Statuses that hold the item for their dates (approved bookings past their payment deadline do not). */
    public const BLOCKING = [self::AWAITING_PAYMENT, self::CONFIRMED, self::HANDOVER_READY, self::IN_RENTAL, self::RETURN_PENDING, self::DISPUTED];

    public const ALL = [self::AWAITING_PAYMENT, self::CONFIRMED, self::HANDOVER_READY, self::IN_RENTAL, self::RETURN_PENDING,
        self::COMPLETED, self::CANCELLED, self::EXPIRED, self::REFUNDED, self::DISPUTED];

    /** Request statuses (before a booking exists). */
    public const REQUEST_STATUSES = ['pending', 'accepted', 'declined', 'cancelled', 'expired'];

    /**
     * Transitions people can trigger from the booking page: from => [to => roles allowed].
     * Confirmation (payment) and expiry are system-only and live in BookingService.
     */
    private const USER_TRANSITIONS = [
        self::AWAITING_PAYMENT => [self::CANCELLED => ['renter', 'owner']],
        self::CONFIRMED => [self::HANDOVER_READY => ['owner'], self::IN_RENTAL => ['owner', 'renter']],
        self::HANDOVER_READY => [self::IN_RENTAL => ['owner', 'renter']],
        self::IN_RENTAL => [self::RETURN_PENDING => ['renter'], self::COMPLETED => ['owner']],
        self::RETURN_PENDING => [self::COMPLETED => ['owner']],
    ];

    private const LABELS = [
        'pending' => 'Requested — waiting for the owner',
        'declined' => 'Declined by the owner',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        self::AWAITING_PAYMENT => 'Approved — awaiting payment',
        self::CONFIRMED => 'Confirmed',
        self::HANDOVER_READY => 'Ready for collection',
        self::IN_RENTAL => 'In rental',
        self::RETURN_PENDING => 'Returned — awaiting owner check',
        self::COMPLETED => 'Completed',
        self::REFUNDED => 'Refunded',
        self::DISPUTED => 'In dispute',
    ];

    /** Button text for each user transition target. */
    private const ACTIONS = [
        self::CANCELLED => 'Cancel booking',
        self::HANDOVER_READY => 'Mark ready for collection',
        self::IN_RENTAL => 'Confirm item handed over',
        self::RETURN_PENDING => 'I have returned the item',
        self::COMPLETED => 'Confirm item returned',
    ];

    public static function canTransition(string $from, string $to, string $role): bool
    {
        return in_array($role, self::USER_TRANSITIONS[$from][$to] ?? [], true);
    }

    /** @return array<string, string> target status => button label, for this role */
    public static function actionsFor(string $from, string $role): array
    {
        $actions = [];
        foreach (self::USER_TRANSITIONS[$from] ?? [] as $to => $roles) {
            if (in_array($role, $roles, true)) {
                $actions[$to] = self::ACTIONS[$to];
            }
        }
        return $actions;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** CSS modifier for status badges. */
    public static function tone(string $status): string
    {
        return match ($status) {
            'pending', self::AWAITING_PAYMENT, self::RETURN_PENDING => 'waiting',
            self::CONFIRMED, self::HANDOVER_READY, self::IN_RENTAL => 'active',
            self::COMPLETED => 'done',
            self::DISPUTED => 'alert',
            default => 'closed',
        };
    }
}
