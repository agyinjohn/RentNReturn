# Step 3 report — RentNReturn verification ✓

Prepared for Darren / RentNReturn, following "What to send Darren after each stage". Branch:
`claude/client-requirements-review-w0l4ht`. Builds on Steps 0–2.

## What changed

| Checklist item | Status | How |
|---|---|---|
| Verified email | ✅ (Step 1) | Unchanged. |
| Verified mobile number with OTP/code | ✅ | Profile → Mobile → *Verify*: we text a 6-digit code (expires in 10 minutes, single use, 5 wrong tries then it's dead). Only a scrambled (bcrypt) copy of the code is stored. **UK mobiles only** (`07…` / `+447…`); see SMS cost protection below. A number can be verified on **one account only**. Changing number means verifying the new one. |
| Identity-verification status for users who need the higher trust level | ✅ (structure) / ⏳ (provider) | Status, provider, provider reference and timestamps are recorded in `user_verifications`, with full history. **No documents, selfies or ID numbers are ever stored by RentNReturn**; the provider keeps them. A real provider (Stripe Identity or Onfido) needs an account, so for now there's a **development-only simulated provider** with pass/fail buttons, which makes the demo possible. The app **refuses to start** on staging/production with the simulator switched on. Without a provider, the profile says "Identity checks are coming soon". |
| Keep legal ID / private information off public profiles | ✅ | Public pages show the **display name** (or first name), the badge, verification ticks, member-since and completed rentals. Never the full legal name, email, phone or ID details (tested). New **public profile page** `/people/{id}`, linked from listings and booking pages. |
| Verified ✓ rule from trusted server-side results; users can't switch it on | ✅ | Badge = verified email **and** verified mobile **and** passed identity check. It is **not a stored flag**: it is worked out each time from three timestamps that only server code can set (email link, SMS code, provider result). The rule is defined once (`src/Verification/TrustBadge.php`) and used by every page. |
| Display name / first name instead of full legal name | ✅ | New "Public display name" on the profile, with the legal name marked private. If left blank, the first name is shown. Display names can't contain phone numbers or web addresses (keeps contact and payment on the platform) or "RentNReturn" (stops staff impersonation). |
| Re-verification / withdrawal if the provider reports expiry or failure, or risk changes | ✅ | A provider result of failed, expired or withdrawn removes identity status, and with it the badge; the user can verify again. A late failure of an *old* abandoned check does not remove a newer successful one. Operators can withdraw with a recorded reason: `php bin/verification.php withdraw <email> "<reason>"` (an admin screen comes in Step 7). |
| Stripe KYC kept separate from the public badge | ✅ (by design) | The badge only reads the three verification timestamps. Stripe Connect onboarding/KYC status for payouts arrives in Step 4 as separate fields and will never affect the badge. |

### SMS cost and abuse protection
- Codes per account: 3 an hour, 5 a day. Codes per destination number: 3 an hour, across all accounts.
- **UK mobiles only.** "SMS pumping" fraud triggers verification texts to expensive international numbers to run up the bill.
- No text is sent to a number already verified on another account.
- Wrong-code limits hold even when guesses are fired in parallel from several logged-in sessions; the attempt counter is atomic (tested).

### SMS sending
`SMS_DRIVER=log` (development) writes texts to `storage/logs/sms.log`. `SMS_DRIVER=twilio` sends through Twilio's API with `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN` and `TWILIO_FROM` (a UK number or a Messaging Service SID). The app refuses to start on staging/production with the log driver or missing Twilio settings.

## Database migration

`storage/migrations/2026_10_18_step3_verification.sql` (back up, then `php bin/migrate.php`, staging first). It adds:
- `users.display_name` and `users.identity_verified_at`;
- a unique key on `users.phone`, which now holds only a verified number;
- the `phone_verifications` table (hashed codes);
- the `user_verifications` table (history).

Existing users keep their accounts; they simply have no verified mobile or identity yet.

## Test results

`composer test`: **144 tests, 1,065 assertions, all passing** on PHP 8.3 + MariaDB 10.11 locally; CI runs them on PHP 8.1 + MySQL 8.0 too.

| Pass gate / requirement | Tests |
|---|---|
| **Badge cannot be self-set or set by editing a request** | `TrustBadgeTest::testBadgeCannotBeSetByEditingTheRequest`: a forged profile form with `email_verified_at`, `phone_verified_at`, `identity_verified_at`, `phone`, `verified`, `badge`... changes nothing. The simulator returns 404 unless enabled in development. |
| Nobody can complete someone else's check | `testNobodyCanCompleteSomeoneElsesCheck` |
| Badge only with all three | `testBadgeAppearsOnlyWhenEmailMobileAndIdentityAreAllVerified`: email only, then + mobile, then a failed identity check (no badge each time), then a passed check (badge on listing, cards and public profile). The full history is recorded. |
| Withdrawal / expiry / re-verification | `testProviderExpiryOrWithdrawalRemovesTheBadgeAndAllowsReverification`, `testLateFailureOfAnOldCheckDoesNotRemoveANewerVerification` |
| No private data on public pages | Same badge test: the legal surname, email and phone are absent from listing, browse and profile pages. `BookingFlowTest` checks booking history uses public names. |
| Mobile code | `PhoneVerificationTest`: hashed storage, single use, expiry, 5-try limit, **parallel guessing limit**, new code kills the old one, UK-only, one account per number, rate limits per account and per number, real **Twilio driver** against a fake Twilio API (correct request, credentials, friendly failure) |
| Production safety | `ConfigValidatorTest`: log SMS driver, missing Twilio credentials and the simulated identity provider are each refused on staging/production |
| Rules | `VerificationRulesTest`: UK number formats accepted, landlines/US/premium ranges refused; badge needs all three; display-name rules |

**Mutation checks:**
- Dropping the identity condition from the badge rule fails 2 tests.
- Letting the simulator accept any reference fails 1 test.
- Removing the atomic attempt counter fails the parallel-guess test in 2 runs out of 3. This one is a genuine race, so whether it's caught depends on timing; the correct code passes every time.

## Decisions / actions for Darren

1. **Choose an identity provider.** Recommendation: **Stripe Identity**, because Stripe is already being used for payments (one supplier, one contract, roughly £1–2 per check). Onfido is the main alternative. Integration means a provider class plus a signed webhook, and it fits best alongside the Step 4 Stripe webhook work.
2. **Twilio account** (or another SMS provider) with a UK sender, for staging and production.
3. **Badge rule:** confirm "email + mobile + identity". Should anything require the badge, e.g. listing high-value items or renting without a deposit? Currently it is informational only.
4. **Who must verify identity?** Currently optional for everyone. The spec says "for users who require the higher trust level"; a rule (e.g. owners before their first payout) can be added in Step 4.
5. **UK mobiles only:** OK for the Aberdeen pilot? International numbers can be allowed later per country.
6. **Private booking pages** now show display names too. If renter and owner should see each other's full names once a booking is confirmed (e.g. for handover), that's a small change.

## How to demo (Stop & show Darren)

Run locally with `IDENTITY_PROVIDER=simulated` (development only):
1. Show an **unverified** account: no badge on its listing; the profile shows what's missing.
2. Profile → Mobile → enter `07700 900123` → the code appears in `storage/logs/sms.log` → enter it → Mobile ✓.
3. Profile → Identity → *Verify* → the simulated provider page → "check failed" → no badge, "try again" offered.
4. *Try again* → "check passed": the badge appears on the profile, the listing, the browse cards and the public profile, which shows the first name only.
5. `php bin/verification.php withdraw owner@… "demo"` → the badge disappears; `php bin/verification.php status owner@…` shows the full history.
6. Show `composer test` and the green CI run.
