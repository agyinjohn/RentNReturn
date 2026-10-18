# Step 0 + Step 1 report — clean baseline, security and accounts

Prepared for Darren / RentNReturn, following "What to send Darren after each stage" in the build checklist.
Built on the Fixed Foundation (5 Oct 2026). Branch: `claude/client-requirements-review-w0l4ht`.

## What changed

### Step 0 — clean source package and deployment baseline

| Checklist item | Status | How |
|---|---|---|
| Clean source package (no `.env`, `.git`, `.claude/worktrees`, temp AI files, uploads, IDE files) | ✅ | `php bin/package.php` builds a zip from committed code only (git archive) and **rejects** it if it finds `.env` files, uploads, logs, `.claude`/IDE folders, or anything shaped like an API key/password. `.gitignore` covers all of these. |
| Rotate exposed credentials | ⚠️ **Action for Darren/Nasira** | Can't be done in code. The original MVP zip contained `.env` files: revoke and re-issue the AI keys, change the DB password and any SMTP passwords. See docs/OPERATIONS.md §6. |
| Separate dev / staging / production config; production debug off + HTTPS | ✅ | All settings from environment variables (`.env.example` documents them). App **refuses to start** on staging/production with debug on, http:// URL, non-Secure cookies, no SMTP or empty DB password. Plain-HTTP requests redirect to the HTTPS `APP_URL`; HSTS sent. `php bin/check-config.php` checks a server. |
| Versioned database migrations | ✅ | `php bin/migrate.php` (`--status`): builds an empty DB from `schema.sql`, otherwise applies pending `storage/migrations/*.sql` in order, recorded in `schema_migrations`. Migrations are safe to re-run. |
| Structured logging + error monitoring; generic errors for users | ✅ | JSON-lines log `storage/logs/app-YYYY-MM-DD.log` with request ID, path, user, exception and trace. Users get a generic page with a **reference** that matches exactly one log entry. Uptime and log alerting setup is documented (needs an account with a provider). |
| Backup + documented restore | ✅ | `php bin/backup.php --keep=14` (database + photos + checksummed manifest); `php bin/restore.php <dir> --yes` refuses corrupt backups. Runbook in docs/OPERATIONS.md §4. Tested end-to-end: backup → restore into a second database → identical data and photos. |
| Brand RentNReturn + GBP | ✅ | Remaining "P2P" naming removed from composer/README. The default database name `p2p_rent` is unchanged on purpose: existing installs use it, and visitors never see it. |

### Step 1 — security and account hardening

| Checklist item | Status | How |
|---|---|---|
| CSRF on every state-changing action | ✅ (foundation) + tested | Every POST checked centrally; tokens rotate at login. |
| Session security; regenerate at login | ✅ | Strict mode (server ignores attacker-chosen session IDs), HttpOnly, SameSite=Lax, Secure outside development, 2-hour idle timeout. **New:** changing the password signs the user out on every other device. |
| Logout is a secure POST | ✅ (foundation) + tested | |
| Login rate limiting | ✅ **rebuilt** | The foundation version kept the counter in the session, so clearing cookies reset it. Limits now live in the database: 5 failures per email, 30 per IP, both per 15 minutes. The same message is shown for unknown emails and wrong passwords, with timing equalised. |
| Password reset | ✅ **new** | `/forgot-password`: same response whether the account exists or not. Links last 1 hour, are single-use, and only a SHA-256 hash is stored. Requesting a new link kills the old one. Completing a reset signs out other sessions, clears the login lock and sends a "password changed" email. Limited to 3 requests per email and 10 per IP per hour. |
| Email verification | ✅ **new** | Signup sends a 24-hour single-use link, and "resend" is limited to 3 per hour. **Creating, publishing, requesting and accepting** need a verified email; browsing and profile don't. Unverified users see a banner. Signup no longer reveals whether an email is registered: the real owner gets an email instead. |
| Real email delivery | ✅ **new** | SMTP via PHPMailer (works with Postmark, SES, Brevo, Mailgun, Microsoft 365...). In development, emails are written to `storage/logs/mail.log`. |
| Secure image uploads | ✅ | Image actually decoded; JPEG/PNG/WebP only; 5 MB max; 100 px minimum and 10,000 px / 50 MP maximum dimensions, which stops "decompression bomb" images. Random filenames. Scripts can't run from `uploads/`. Uploads over PHP's own size limit now get a clear message instead of "Please upload one photo". |
| AI temp files out of public storage + always deleted | ✅ | Draft photos now live in `storage/uploads/drafts` (outside the web root) and are served only to their owner. AI temp files go to `storage/tmp` and are always deleted. `bin/cleanup.php` removes abandoned drafts after 24 hours. |
| Rate-limit AI requests (API cost) | ✅ **new** | 10 per user per hour and 30 per day (configurable). Rejected uploads don't count. Over the limit, the owner writes the description themselves instead of being blocked. |
| Fix "Request accepted" after a failed DB action | ✅ (foundation) + tested | |
| Security headers + production error handling | ✅ | Content-Security-Policy (scripts only from our own site), X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, HSTS outside development. |
| Mobile number + OTP | ➡️ moved to Step 3 | The spec lists it in Stage 1, but the checklist puts it in Step 3 ("verified mobile number with OTP/code"). It needs an SMS provider account (e.g. Twilio, Vonage), so it is grouped with the Step 3 verification work. |

## Database migrations

| File | What it does |
|---|---|
| `storage/migrations/2026_10_05_phase2_foundation.sql` | Foundation migration, fixed: now re-runnable, removes duplicate slugs before adding the unique key, and widens `bookings.status` before the status update. |
| `storage/migrations/2026_10_18_step1_accounts.sql` | Adds `users.session_version`, `user_tokens` (hashed verification/reset tokens) and `rate_limits`. |

Apply with `php bin/migrate.php` after `php bin/backup.php`, **on staging first**.
**Existing users** keep their accounts and listings but start unverified: the banner asks them to verify before creating listings or renting. If you'd rather mark all existing users as verified, that is a one-line SQL statement. Decide before running this on production.

## Test results

`composer test`: **52 tests, 430 assertions, all passing** on PHP 8.3 + MariaDB 10.11 locally. CI (`.github/workflows/tests.yml`) runs them on every push on PHP 8.1 + MySQL 8.0 and PHP 8.3 + MariaDB 10.11; both passed for the Step 0/1 commit.
The integration tests run the real app on a web server and use it over HTTP, the way a browser would.

| Pass gate requirement | Tests |
|---|---|
| CSRF rejection | `CsrfAndSessionTest`: missing, forged and cross-session tokens rejected (419), and the action does not happen |
| Session rotation | session ID changes at login; an attacker-chosen session ID is refused; HttpOnly/SameSite cookie; password change signs out other sessions |
| Protected logout | GET `/logout` does nothing; POST without a token is rejected; POST with a token ends the session |
| Rate limiting | `LoginRateLimitTest`: lock after 5 failures even with the right password; survives clearing cookies; other accounts unaffected; same message for unknown emails. Also covered: reset, resend and AI limits |
| Safe upload rejection | `UploadSecurityTest`: PHP-in-.jpg, GIF/PHP polyglot, corrupt JPEG, too small, 12000 px wide, over 5 MB and over PHP's limit are all rejected and nothing is stored; temp files deleted; drafts not public |
| Working password reset | `PasswordResetTest`: full flow; single use; 1-hour expiry; hash-only storage; old link killed by a new one; identical response for unknown emails; other sessions signed out |
| Working email verification | `EmailVerificationTest`: signup email; blocked from create/publish/request/accept/AI until verified; link single-use and expiring; resend; signup doesn't reveal accounts |
| Generic errors + logging | `ErrorHandlingTest`: a database failure shows a generic page with a reference, no exception detail, and the reference matches exactly one log entry with the full exception. A failed accept never shows success |
| Production config | `ConfigValidatorTest`: debug on, http, insecure cookies, log mailer, missing SMTP host and empty DB password are each refused on staging and production |
| Migrations | `DatabaseServicesTest`: a simulated MVP database upgrades to exactly the same columns as a fresh install, and a re-run is a no-op |

As a check that the tests are meaningful, I removed the CSRF check, the session regeneration and the per-email login limit one at a time; the matching tests failed each time.

## Not done / needs a decision

1. **Credential rotation** (above) is a manual task.
2. **Choose an SMTP provider** and set up SPF/DKIM for the sending domain. Staging won't start without SMTP settings.
3. **Existing users and verification:** require them to verify (current behaviour) or mark them verified?
4. ~~Pre-existing bug: no AI key = no listings.~~ **Fixed.** Previously, with no AI key configured, or when the AI call failed, users could not create a listing at all. Now the photo is kept and the owner writes the title and description themselves. The same happens when they hit the AI limit, with a short explanation. Covered end to end by `ListingCreationTest` (no key, working AI and failing AI each produce a saved listing with its photo).
5. The migration was tested against a **simulated** copy of the original MVP database. It must still be run against a backup of the real staging database.
6. Mobile OTP and identity verification → Step 3.

## How to demo (Stop & show Darren)

1. `php bin/migrate.php`, start the site, sign up. Show the email in `storage/logs/mail.log` (or a real inbox on staging).
2. Log in before verifying: show the banner and that "List an item" is blocked. Click the link: now allowed.
3. Log out, then use "Forgot your password?": reset it, show the old password fails and the old link no longer works.
4. Try 6 wrong passwords: show the lock, then that a reset clears it.
5. Try uploading a renamed `.php` file as a photo: rejected.
6. Show `composer test` passing and the green CI run on GitHub.
