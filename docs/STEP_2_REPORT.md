# Step 2 report — rental dates, pricing and double-booking

Prepared for Darren / RentNReturn, following "What to send Darren after each stage". Branch:
`claude/client-requirements-review-w0l4ht`. Builds on Step 0/1 (`docs/STEP_0_1_REPORT.md`).

## What changed

| Checklist item | Status | How |
|---|---|---|
| No dates in the past | ✅ | "Today" is the **UK** date (Europe/London). Previously it used the server's UTC date, which allowed booking yesterday for an hour after midnight in summer. |
| No dates outside available-from / available-until | ✅ | Checked when the renter requests **and again when the owner approves**, along with whether the listing is still published. The date pickers also limit choices to the window. |
| One rental-day rule, money on the server in pennies | ✅ | `src/Booking/RentalPeriod.php` is the only place days are counted: **both dates count** (Mon–Wed = 3 days; same day = 1 day). Prices are stored as whole pennies (`price_daily_pence`); totals are days × price, calculated on the server. Anything the browser sends as a price or total is ignored, and a test sends fake totals to prove it. |
| Prevent overlapping rentals | ✅ | An approved or confirmed booking holds its dates. Sharing a single day counts as an overlap, so a booking ending on the 5th blocks one starting on the 5th; the 6th is free. |
| Re-check availability when the owner accepts | ✅ | Approval re-reads everything inside a database transaction. |
| Transaction/locking so two people can't secure the same item | ✅ | Every booking change **locks the listing row first**, then re-checks. Changes to one item happen one at a time, and because every change locks in the same order, deadlocks can't happen. Proven by concurrency tests (below). |
| GBP everywhere | ✅ | Formatting goes through one helper (`Money::format` → "£1,250.00"). |
| Owner acceptance = "Approved — awaiting payment" | ✅ | Unchanged from the foundation, now with a **payment deadline**: 24 hours, or the start of the rental's first day if sooner, but never less than 60 minutes. After the deadline the dates are released automatically: immediately for new requests, and the status changes to "Expired" on the next page view or hourly cleanup. |
| Clear booking status and history for renter and owner | ✅ | New shared page `/rentals/{id}`: status badge, dates, price breakdown, payment deadline, the actions that party may take, and a **history timeline** (who did what, when, in UK time). Strangers get "not found". "My requests" and the Dashboard link to it, with status badges and totals. |
| Database constraints | ✅ | Unique slug (foundation), plus CHECK constraints: price > 0, valid availability window, end ≥ start, status must be a known value, total = days × daily price. These are enforced by MySQL 8.0.16+ and MariaDB 10.2+; older servers don't support CHECK constraints, so the app's own checks apply there. |

### Booking lifecycle

```
requested ──approve──▶ approved_awaiting_payment ──payment (Stripe only, Step 4)──▶ confirmed
   │                        │             │                                          │
decline / cancel /       cancel       expired (deadline)                ready for collection (optional)
expired (start passed)                                                               │
                                                     in_rental ◀────────────────────┘
                                                         │
                                       returned — awaiting owner check ──▶ completed
```

| Action | Who |
|---|---|
| Cancel a pending request | renter |
| Approve / decline | owner |
| Cancel an approved, unpaid booking | renter or owner |
| **Confirm (paid)** | **only the payment system.** `BookingService::confirmPayment()` is ready for the Step 4 Stripe webhook; no button or form can do it (tested with forged requests) |
| Ready for collection | owner |
| Handed over (in rental) | owner or renter |
| Returned | renter |
| Return confirmed (completed) | owner |

A paid booking can't simply be cancelled yet, because that needs refunds (Step 6). "Refunded" and "disputed" statuses are reserved for Steps 6–7.

Other rules added:
- **Duplicate requests:** a renter can't send a second pending request for overlapping dates.
- **Owner never answers:** a pending request whose start date passes becomes "Expired".
- **Late approvals:** approving a request whose start date has already passed is refused.
- **Price changes:** the price is fixed when the renter requests, so changing the listing price later doesn't affect existing bookings.

## Database migration

`storage/migrations/2026_10_18_step2_bookings.sql` (run `php bin/backup.php` then `php bin/migrate.php`, staging first). It:
- converts `listings.price_daily` (decimal) into `price_daily_pence` (integer) and removes the old column;
- adds the quoted price, day count and total to requests and bookings (existing rows are backfilled at the listing's current price);
- adds `payment_due_at` and `confirmed_at` to bookings;
- creates `booking_events` (history), rebuilding entries for existing requests;
- adds the CHECK constraints.

Tested on a simulated copy of the original MVP database: prices converted exactly (£5.00 → 500), totals and history rebuilt, and a re-run is a no-op. A migrated database matches a fresh install column for column (a test checks this).

## Test results

`composer test`: **108 tests, 887 assertions, all passing** on PHP 8.3 + MariaDB 10.11 locally; CI runs the same on PHP 8.1 + MySQL 8.0.

| Pass gate requirement | Tests |
|---|---|
| Past / out-of-window dates fail | `BookingFlowTest::testPastOutOfWindowAndInvalidDatesAreRefused`, plus `RentalPeriodTest`: UK-midnight edge case, window boundaries, clock changes, leap day, length and advance limits |
| Two users cannot get overlapping bookings | `testTwoRentersSameDatesOnlyOneCanBeApproved` (the Darren demo: both may *ask*, only one is approved, and the other can be approved once the first is cancelled), `testSharingAnEndDateIsAnOverlapButTheNextDayIsFree`, `testUnpaidApprovalExpiresAndReleasesTheDates` |
| Concurrent acceptance can't create duplicates | `BookingConcurrencyTest`: (1) **deterministic race**: while another transaction holds the item's lock, the owner's accept is shown to wait, then sees the other booking and refuses; (2) **burst**: 8 overlapping requests accepted at the same instant on an 8-process server give exactly 1 booking |
| Concurrent payment can't double-confirm | `testSimultaneousPaymentConfirmationsConfirmOnce`: 6 processes confirm the same booking at the same moment; exactly one "confirmed" and one history entry |
| Money server-side in pennies, tampering ignored | `testPriceIsCalculatedOnTheServerAndTamperedTotalsAreIgnored`, `MoneyTest` (includes classic floating-point traps such as 0.29 and 4.35), `testBookingSnapshotsTheRequestPrice` |
| Lifecycle and permissions | `testFullLifecycleWithRoleChecks` (every step tried by the wrong party first; forged "confirmed" refused), `testRequestPhaseActions`, `BookingStatusTest` |
| Shared history, private to the two parties | `testRenterAndOwnerShareTheBookingPageAndHistoryOthersCannotSeeIt` |
| DB constraints | `BookingServiceTest::testDatabaseConstraintsRejectBadRows`: 8 kinds of bad row rejected by the database itself |

**Mutation checks:**
- Removing the listing lock makes the deterministic race test fail: the two transactions deadlock, which users would see as an error page.
- Removing the overlap check at approval makes three tests fail.

## Decisions for Darren (current defaults in brackets; all are one-line config changes)

1. **Day counting** [both dates count]. The alternative is "nights" (Mon–Wed = 2), which would also let one rental end and the next start on the same day.
2. **Payment window** [24 h, capped at the start of the rental, minimum 60 min] — `BOOKING_PAYMENT_WINDOW_HOURS`.
3. **Maximum rental length** [60 days] — `BOOKING_MAX_RENTAL_DAYS`. Long rentals also affect deposits (Step 6).
4. **How far ahead people can book** [365 days] — `BOOKING_MAX_ADVANCE_DAYS`.
5. **Maximum daily price** [£10,000].
6. **Existing approvals from before this migration** have no payment deadline, so they never expire automatically. Decide whether to give them a deadline or cancel them before payments go live.
7. **Platform fee (10%)** is not yet added or deducted: whether the renter pays on top or it comes out of the owner's payout is a Step 4 decision. Totals shown now are the rental price only.
8. **Step 4 note:** Stripe Checkout sessions must expire no later than the booking's payment deadline, so payment can't arrive after the dates are released. (If one did, `confirmPayment` returns "expired" and it is refunded.)

## How to demo (Stop & show Darren)

1. Log in as two renters in two browsers. Both request the same drill for overlapping dates; each sees the price breakdown.
2. As the owner, approve the first: "Approved — awaiting payment". Approving the second fails with "already been reserved".
3. A third renter trying those dates is told they are no longer available.
4. The first renter cancels from their booking page; the owner can now approve the second.
5. Open the booking page as renter and as owner to show the same history; open it as anyone else to show "not found".
6. Show `composer test` and the green CI run, including the concurrency tests.
