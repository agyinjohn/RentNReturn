# Step 4 report — Stripe Connect marketplace payments (test mode)

Prepared for Darren / RentNReturn, following "What to send Darren after each stage". Branch:
`claude/client-requirements-review-w0l4ht`. Builds on Steps 0–3.

## How money flows

```
Renter ──pays £47.97 (Stripe Checkout)──▶ RentNReturn's Stripe account
                                           │  10% fee (£4.80) stays with RentNReturn
                                           │  owner's share (£43.17) held as a payout
                                           ▼  …when the rental is completed:
                                        transfer ──▶ Owner's Stripe Express account ──▶ their bank
```

This is Stripe's **"separate charges and transfers"** model. The renter pays RentNReturn, and the owner's share is transferred **later**. This lets payouts wait until the item comes back and be held during disputes (Steps 6–7). Paying the owner at checkout would make that impossible.

## What changed

| Checklist item | Status | How |
|---|---|---|
| Stripe Connect for owners receiving payouts | ✅ | New **Payouts** page: *Set up payouts with Stripe* creates a Stripe **Express** account (UK) and sends the owner to Stripe's own onboarding (ID, bank details). We store only the account ID plus "details submitted / charges enabled / payouts enabled" and what Stripe still needs. These stay up to date via the `account.updated` webhook and are **separate from the public Verified badge**. |
| Store Stripe customer / connected-account references safely | ✅ | `users.stripe_customer_id` (renters) and `connected_accounts` (owners). No card or bank details are ever stored by RentNReturn. |
| A payment record for each booking attempt | ✅ | `payments`: rental amount, fee, amount charged, owner share, refunded amount, GBP, Stripe Checkout and payment IDs, status and times. |
| Rental amount and commission calculated on the server | ✅ | One fee rule (`src/Payments/Fees.php`), integer pennies, rounded half-up. The browser only says "pay booking X"; tests send fake amounts and they are ignored. A paid amount that doesn't match the booking is **not** confirmed and is flagged. |
| Signed Stripe webhooks | ✅ | `POST /webhooks/stripe`. The signature is checked with the endpoint secret, and events older than 5 minutes are rejected (stops replays). Events from the wrong mode (live vs test) are ignored. |
| Store webhook event IDs so the same event can't be processed twice | ✅ | `webhook_events` with a unique event ID. A repeat delivery is acknowledged without doing anything. Two copies arriving at once are handled by letting only one process it while the other is told to retry. The handlers themselves are also repeat-safe, and the ledger can't hold the same Stripe charge, refund or transfer twice. |
| ONLY a verified Stripe webhook can mark a booking PAID / CONFIRMED | ✅ | Coming back from Stripe's page shows "we're confirming your payment" and changes nothing. Forged, unsigned or replayed webhooks are rejected (tested). |
| Failed, cancelled and expired payments handled safely | ✅ | An abandoned checkout leaves the booking payable until its deadline, and the renter can try again. A double click reuses the open payment page instead of creating a second. If a payment arrives **after** the deadline released the dates, it is **refunded automatically**. A second successful payment for an already-paid booking is refunded automatically. Out-of-order events (e.g. "expired" after "paid") are ignored. |
| Refund records and owner payout records | ✅ | `refunds` (written *before* calling Stripe, so a failure is recorded for retry; counted only when Stripe confirms success) and `payouts` (held → sent, or waiting for the owner's payout setup, or failed and retried hourly). |
| Every money movement recorded | ✅ | `payment_ledger`, append-only: charge, platform fee, refund, fee returned with a refund, transfer to owner. `php bin/payments.php status <booking>` prints it. |
| Commission in server configuration | ✅ | `PLATFORM_FEE_BPS=1000` (10%), recorded on every payment. Who pays it is configurable: `PLATFORM_FEE_PAYER=owner` (default, deducted from the owner's share) or `renter` (added to the renter's total as a separate "service fee" line). |
| **Stripe TEST MODE only** | ✅ | The app **refuses to start with live Stripe keys** in any environment, including development, unless `PAYMENTS_LIVE_APPROVED=yes` is set after Darren signs off. |

### Cancellations and refunds built so far
- **Owner cancels a paid booking** (before handover): the booking is cancelled, the renter is refunded **in full**, the owner's payout is cancelled and the commission is returned. Shown on the booking page as "Cancel and refund renter in full".
- **Operator refunds** (full or partial), until the admin area (Step 7): `php bin/payments.php refund <booking> <£> "<reason>"`. A partial refund reduces the owner's pending payout in proportion, and RentNReturn returns its share of the fee.
- **Renter cancels a paid booking:** not built yet, because it needs the cancellation and deposit policy (Step 6).

### Owner payout timing (default; Step 6 decides the final rule)
The owner's share is sent when the **owner confirms the item came back** (booking "completed"). If the owner hasn't finished payout setup, it waits and is sent automatically as soon as Stripe enables their account. Failed transfers are retried hourly (`bin/cleanup.php`), and each retry can only ever pay once.

## Database migration
`storage/migrations/2026_10_18_step4_payments.sql` (back up, then `php bin/migrate.php`, staging first). It adds `users.stripe_customer_id` and the tables `connected_accounts`, `payments`, `payment_ledger`, `webhook_events`, `refunds` and `payouts`.

## Test results

`composer test`: **169 tests, all passing** on PHP 8.3 + MariaDB 10.11 locally; CI also runs PHP 8.1 + MySQL 8.0. There's no Stripe account here, so the payment tests run the **official Stripe PHP SDK** against a **local fake Stripe API** (`tests/fake_stripe.php`), with webhooks **signed exactly as Stripe signs them**.

| Pass gate requirement | Tests (`PaymentFlowTest`, `FeesTest`, `ConfigValidatorTest`) |
|---|---|
| Complete test-mode rental payment | `testCheckoutUsesServerAmountsAndOnlyASignedWebhookConfirms`: amount from the server, correct line items, transfer group, metadata, idempotency key and session expiry. Returning from Checkout confirms nothing; forged, unsigned or empty signatures are rejected; the signed webhook confirms the booking and records charge + fee + held payout. |
| RentNReturn fee | `FeesTest` (both payer models, rounding, always adds up); `testRenterPaysTheFeeWhenConfiguredThatWay` |
| Booking confirmation only from webhooks; no double processing | the test above, plus `testConcurrentDuplicateWebhooksAreProcessedOnce` (7 deliveries at once, two different event IDs: one charge, one payout, one confirmation) and `testStaleOrWrongModeWebhooksAreRejected` |
| Owner payment / payout state | `testOwnerOnboardingAndPayoutWhenTheRentalIsCompleted` (onboarding, no transfer before completion, then a transfer of £43.17 to the owner's account tied to the original charge), `testPayoutWaitsForTheOwnersAccountThenIsReleased`, `testFailedTransferIsRetriedWithoutPayingTwice` |
| Failed / cancelled / expired payments | `testAbandonedCheckoutLeavesBookingPayableAndOutOfOrderEventsAreIgnored`, `testPaymentAfterTheDeadlineIsRefundedAutomatically`, `testWrongAmountIsNotConfirmed`, `testOpenCheckoutIsReusedAndOnlyTheRenterCanPay` |
| Refund and payout records | `testOwnerCancellingAPaidBookingRefundsTheRenterInFull` (including a late re-sent "paid" event that must not undo the refund), `testPartialRefundReducesThePayoutAndFailedRefundsAreRecorded`, `testPendingRefundIsCountedOnlyWhenStripeConfirmsIt` |
| Test mode only | `testLiveStripeKeysAreRefusedEvenInDevelopmentUntilSignedOff` |

**Bug the tests caught:** Stripe remembers a request's result **including errors** under its idempotency key. A failed owner transfer retried with the same key would have failed again for 24 hours, and the owner wouldn't have been paid. Each retry now uses a new key, while resuming a half-finished attempt reuses its key so it can't pay twice.

**Mutation checks:**
- Skipping the signature check fails 2 tests.
- Removing the amount check fails 1 test.
- Removing the "already recorded" guard fails 1 test (a re-sent "paid" event after a refund).

## What Darren / Nasira need to do

1. **Create a Stripe account** (UK) and switch on **Connect** (Express accounts). Use **test mode** keys: `STRIPE_SECRET_KEY=sk_test_…` and `STRIPE_PUBLISHABLE_KEY=pk_test_…`.
2. **Add the webhook endpoint** `https://<staging-domain>/webhooks/stripe` in the Stripe dashboard. Select these events:
   - `checkout.session.completed`
   - `checkout.session.expired`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.async_payment_failed`
   - `refund.created`, `refund.updated`, `refund.failed`
   - `account.updated` (enable "Listen to events on Connected accounts" for this one)

   Then put the signing secret in `STRIPE_WEBHOOK_SECRET`. Full steps are in `docs/OPERATIONS.md`.
3. **Decide who pays the 10% fee.** Currently it's deducted from the owner (renter sees the listed price). The alternative is the renter paying it on top. Note that **Stripe's own card fees (about 1.5% + 20p for UK cards) come out of RentNReturn's 10%**, so the net take is roughly 8%.
4. **Confirm payout timing:** owner paid when they confirm the return (current), or a fixed delay after the rental ends, e.g. 24–48 hours to allow damage claims? This is Step 6.
5. **Spec STOP/GO checkpoint:** the spec asks that Stage 4 go to a Stripe/PHP marketplace specialist if the developer isn't confident with Connect, webhooks, idempotency, refunds and payouts. All of these are now implemented and tested, but a short **review by a Stripe-experienced developer before live keys** is still recommended. It's a reviewing job now, not a build.

## How to demo (Stop & show Darren) — on staging, test mode

1. Owner: **Payouts → Set up payouts with Stripe** → complete Stripe's test onboarding (use Stripe's test values) → "Payouts are set up".
2. Renter: request dates; owner approves; renter clicks **Pay £… securely** → Stripe Checkout → test card `4242 4242 4242 4242`, any future date and any CVC.
3. Back on the booking page it says "confirming" until the webhook arrives, then **Confirmed**. Show the event under Stripe → Developers → Webhooks.
4. Owner's booking page shows the payout "Held until the rental is completed". Then: handover → returned → owner confirms the return → payout **Sent to your Stripe account**. Show the transfer in Stripe → Connect.
5. `php bin/payments.php status <booking>` shows the ledger: charge, fee, transfer.
6. A second booking: owner pays, then the owner cancels → full refund shown on both sides and in Stripe.
