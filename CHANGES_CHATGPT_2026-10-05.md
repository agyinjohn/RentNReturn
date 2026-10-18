# RentNReturn foundation fixes — 5 Oct 2026

This is a separate working copy of Nasira’s MVP. The original ZIP was not overwritten.

## Implemented in this copy
- Central CSRF validation for all POST requests and CSRF fields on current forms.
- Logout changed from GET to CSRF-protected POST.
- Session ID regenerated after login; HttpOnly/SameSite cookies and Secure cookies in production.
- Basic session-based login attempt throttling (8 failed attempts / 15-minute window).
- Application branding changed from Rent & Return to RentNReturn in core UI/config.
- Visible listing currency changed from dollars to GBP (£).
- Rental requests reject past dates and dates outside listing availability.
- Rental requests check existing active booking conflicts.
- Owner acceptance now locks/rechecks the request and listing in a DB transaction and prevents overlapping active bookings.
- Accepted request now creates `approved_awaiting_payment`, not a falsely confirmed/paid booking.
- Fixed success-message bug after failed booking transaction.
- Server-side image MIME/content validation added for listing/AI uploads.
- AI API temporary upload files are deleted after processing.
- Basic production security response headers added.
- Fresh schema uses a unique listing slug and payment-ready booking default state.
- Added a Phase 2 foundation migration for existing databases, including verification groundwork fields.
- Added `.env.example` with no credentials.

## Intentionally NOT faked / still requires implementation or provider setup
- Real email verification delivery and password-reset email delivery.
- SMS OTP/mobile verification provider.
- Stripe Connect, cards, Apple Pay, Google Pay, webhooks, refunds and payouts.
- Identity verification provider integration.
- Full admin/dispute system and notifications.
- Production monitoring/backups and infrastructure configuration.
- Automated integration/concurrency test suite.

## Before merging
1. Back up the existing database.
2. Review and run `storage/migrations/2026_10_05_phase2_foundation.sql` on staging first.
3. Configure `.env` from `.env.example`; never commit or share the real `.env`.
4. Regression-test signup/login/profile/listing creation/publish/request/accept/decline.
5. Specifically test overlapping requests using two renter accounts.
6. Do not add live Stripe keys yet.
