# RentNReturn

Peer-to-peer rental marketplace for tools and equipment (UK, GBP). Owners list items; renters request dates;
owners approve. Payments are not live yet — see the Phase 2 plan.

Server-rendered PHP 8.1+ / MySQL (or MariaDB), no framework.

## Quick start (development)

Requirements: PHP 8.1+ with `pdo_mysql`, `fileinfo`, `gd` (tests), `zip`, `curl`; MySQL 5.7+/8 or MariaDB 10.4+; Composer.

```bash
composer install
cp .env.example .env          # set DB_USERNAME / DB_PASSWORD for your local MySQL
php bin/migrate.php           # creates the database and all tables
php -d upload_max_filesize=6M -d post_max_size=32M -S localhost:8080 -t public
```

Open http://localhost:8080. In development, emails (verification, password reset) are not sent — they are written to
`storage/logs/mail.log`; copy the link from there.

**WAMP:** point the Apache document root (or a virtual host) at the `public/` folder, never the project folder.
You can still create the database in phpMyAdmin and import `storage/schema.sql`, but `php bin/migrate.php` is preferred.

## Environments

| | development | staging / production |
|---|---|---|
| `APP_DEBUG` | 1 | **must be 0** |
| `APP_URL` | http://localhost:8080 | **must be https://** |
| Session cookies | not Secure | Secure, HTTPS enforced (HSTS) |
| `MAIL_DRIVER` | `log` | **must be `smtp`** |
| `SMS_DRIVER` | `log` | **must be `twilio`** (with credentials) |
| `IDENTITY_PROVIDER` | `none` or `simulated` | **never `simulated`** |
| `DB_PASSWORD` | may be empty | **required** |

The app refuses to start on staging/production if any of these are wrong. Check a server with `php bin/check-config.php`.
Set real environment variables on servers rather than shipping a `.env` file. All settings are documented in `.env.example`.

## Commands

| Command | Purpose |
|---|---|
| `php bin/migrate.php` | Apply pending database migrations (`--status` to list) |
| `php bin/check-config.php` | Deployment check: safe config, DB reachable and migrated, storage writable |
| `php bin/backup.php --keep=14` | Back up database + listing photos to `storage/backups/` |
| `php bin/restore.php <dir> --yes` | Restore a backup (overwrites the configured database) |
| `php bin/cleanup.php` | Hourly housekeeping: expire unpaid approvals and stale requests, tokens/limits, drafts, temp files |
| `php bin/package.php` | Build a clean, secret-free source zip in `build/` |
| `php bin/payments.php status\|refund\|release-due\|webhooks …` | Inspect a booking's money, refund, release owner payouts, list failed webhooks |
| `php bin/verification.php status\|withdraw\|record …` | View a user's verification history; withdraw or record identity outcomes |
| `composer test` | Run the test suite (needs a MySQL/MariaDB server; see below) |

See [docs/OPERATIONS.md](docs/OPERATIONS.md) for deployment, backups/restore, logs and credential rotation.

## Tests

```bash
TEST_DB_USERNAME=root TEST_DB_PASSWORD=secret composer test
```

The suite creates and drops a `rentnreturn_test` database, starts the app on PHP's built-in server and drives it over
HTTP (CSRF, sessions, login limits, verification, password reset, uploads, error handling, migrations).
CI runs it on every push (`.github/workflows/tests.yml`) against MySQL 8.0 and MariaDB 10.11.

## Features

- **Accounts:** sign up, email verification, log in/out, password reset, profile. Listing, publishing, requesting and
  accepting rentals require a verified email.
- **Verification:** mobile number by SMS code (UK mobiles), identity check through a provider (simulated in
  development until one is chosen), and the public **RentNReturn Verified ✓** badge (email + mobile + identity),
  derived only from server-side results. Public pages use display names and never show legal names or contact
  details; public member pages at `/people/{id}`. See [docs/STEP_3_REPORT.md](docs/STEP_3_REPORT.md).
- **Payments (Stripe, test mode):** renters pay through Stripe Checkout; bookings are confirmed only by signed
  Stripe webhooks; owners get paid through Stripe Connect Express when the rental is completed; refunds, payouts and
  an append-only money ledger. See [docs/STEP_4_REPORT.md](docs/STEP_4_REPORT.md).
- **Listings:** 1–5 photos, AI-suggested title/description (Groq, Gemini or OpenAI; reviewed and edited by the owner
  before saving), category, condition, daily price in £, availability window. Publish/unpublish. With no AI key,
  or if the AI call fails, the owner writes the title and description themselves.
- **Browse:** keyword search, category filter, listing pages.
- **Rentals:** renters request dates (UK dates, both ends inclusive, priced in integer pence on the server); owners
  approve/decline. Approval re-checks availability under a lock on the listing and creates a booking awaiting
  payment with a deadline, after which the dates are released. Shared booking page for renter and owner with the
  lifecycle (confirmed → handover → in rental → returned → completed) and a full history. Online payment is
  Step 4; see [docs/STEP_2_REPORT.md](docs/STEP_2_REPORT.md).

## Security

CSRF tokens on every POST; POST-only logout; strict, HttpOnly, SameSite session cookies regenerated at login with
idle timeout; password changes sign out other sessions; database-backed rate limits on login, signup, password
reset, verification emails and AI requests; uploads validated by decoding the actual image and checking
type, size and dimensions; draft photos kept outside the web root; CSP and other security headers; generic error
pages with a reference ID that matches the structured log.

## Project structure

```
bin/             CLI scripts (migrate, backup, restore, cleanup, package, check-config)
config/          App settings (env-driven), categories, conditions
docs/            Operations runbook and stage reports
public/          Web root (index.php, assets, uploads/)
src/             PHP classes (App\ namespace)
  Booking/       Rental periods, money (pence), booking lifecycle and BookingService
  Payments/      Fees, Stripe gateway, payments/refunds/payouts, Connect onboarding, webhook processor
  Sms/           SMS sender (Twilio, or log file in development)
  Verification/  Phone codes, identity providers, the trust badge rule
  Controllers/   Auth, PasswordReset, EmailVerification, Listings, Requests, Dashboard, Api
  Mail/          Mailer (SMTP via PHPMailer, or log file in development)
  Services/      AI image description providers
storage/         schema.sql, migrations/, and runtime logs/uploads/tmp/backups (not committed)
templates/       PHP view templates (emails/ for email bodies)
tests/           PHPUnit unit and HTTP integration tests
```
