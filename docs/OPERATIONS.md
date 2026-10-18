# RentNReturn — Operations runbook

## 1. Environments

Use three separate environments, each with **its own database, its own credentials and its own email/API keys**:

| | Purpose | URL | Database |
|---|---|---|---|
| development | each developer's machine | http://localhost:8080 | local |
| staging | test every stage before Darren signs off | https://staging.… | staging only — never a copy of production with real personal data unless anonymised |
| production | real users | https://… | production |

Configuration is by environment variables (see `.env.example` for the full list). On servers, set them in the
host control panel / web server / PHP-FPM pool rather than uploading a `.env` file. Real environment variables
always override `.env`.

The app **refuses to start** on staging/production unless: `APP_DEBUG=0`, `APP_URL` is `https://…`,
`MAIL_DRIVER=smtp` with a `MAIL_HOST`, and `DB_PASSWORD` is set. The reason is written to the PHP error log and
shown by `php bin/check-config.php`.

If the site runs behind a load balancer / reverse proxy that terminates HTTPS, set `TRUST_PROXY_HEADERS=1`
so HTTPS detection and client IPs (used for rate limiting) come from `X-Forwarded-Proto` / `X-Forwarded-For`.
Do **not** set it otherwise — clients could spoof their IP to dodge rate limits.

### Web server

- Document root must be `public/`. A deny-all `.htaccess` in the project root and in `storage/` is a safety net if this is misconfigured.
- PHP upload limits: `upload_max_filesize = 6M`, `post_max_size = 32M` (the app allows 5 MB per photo).
  `public/.user.ini` sets these on PHP-FPM/CGI hosts; set them in `php.ini` under mod_php.
- The web server user must be able to write `public/uploads/` and `storage/` (logs, uploads/drafts, tmp, backups).

### Email (SMTP)

Any transactional email provider with SMTP works (Postmark, Amazon SES, Brevo, Mailgun, Microsoft 365...).
Set `MAIL_DRIVER=smtp`, `MAIL_HOST`, `MAIL_PORT` (587 with `MAIL_ENCRYPTION=tls`, or 465 with `ssl`),
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`. Configure SPF/DKIM for the sending domain with the
provider, otherwise verification and reset emails will land in spam. Delivery failures are logged
(`"message":"Email delivery failed"`) and never shown to users.

### SMS (Twilio)

Mobile verification texts go through Twilio: set `SMS_DRIVER=twilio`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`
and `TWILIO_FROM` (a UK number, or a Messaging Service SID starting `MG`). In the Twilio console, restrict
**Geo permissions** to the United Kingdom as a second line of defence against SMS pumping fraud (the app already
accepts only UK mobiles). Failures are logged as `"message":"SMS delivery failed"`.

### Identity verification

`IDENTITY_PROVIDER=none` until a provider is integrated (users see "coming soon"). `simulated` is for development
demos only and is refused on staging/production. To inspect or change a user's status:
`php bin/verification.php status <email>`, `... withdraw <email> "<reason>"`,
`... record <provider> <reference> <verified|failed|expired|withdrawn>`. Every change is kept in `user_verifications`.

## 2. Deploying a release

1. `php bin/backup.php --keep=14` on the target server.
2. Deploy the code (git pull of a tagged commit, or upload a package from `php bin/package.php`).
3. `composer install --no-dev --optimize-autoloader`
4. `php bin/migrate.php`
5. `php bin/check-config.php` — must print `OK`.
6. Smoke test: home page, log in, browse a listing.

Always run steps 1–6 on **staging first**. Never edit production tables by hand in phpMyAdmin; every schema
change is a new file in `storage/migrations/` (named `YYYY_MM_DD_description.sql`, written to be safe to re-run),
and `storage/schema.sql` is updated to match so fresh installs are identical.

## 3. Scheduled tasks

| Schedule | Command |
|---|---|
| hourly | `php bin/cleanup.php` (also expires unpaid approvals and unanswered requests) |
| daily (e.g. 02:15) | `php bin/backup.php --keep=14` |

Linux cron example: `15 2 * * * cd /var/www/rentnreturn && php bin/backup.php --keep=14 >> storage/logs/cron.log 2>&1`.
On Windows use Task Scheduler with the same commands.

## 4. Backups and restore

`bin/backup.php` writes `storage/backups/<UTC timestamp>/` (or `BACKUP_PATH/…`) containing:

- `database.sql.gz` — full `mysqldump --single-transaction` (consistent without locking the site)
- `uploads.zip` — all listing photos
- `manifest.json` — time, environment, git commit and SHA-256 checksums

It needs the `mysqldump`/`mariadb-dump` client; on Windows/WAMP set `MYSQLDUMP_PATH` (e.g.
`C:\wamp64\bin\mysql\mysql8.0.31\bin\mysqldump.exe`). **Copy backups off the server** (another region or
provider). A backup on the same disk does not survive losing the server.

**Restore** (overwrites the configured database and photos):

```bash
php bin/restore.php storage/backups/20261018-021500 --yes
```

It verifies the checksums first and refuses a corrupt backup. Needs the `mysql`/`mariadb` client
(`MYSQL_CLIENT_PATH` on Windows). Then run `php bin/migrate.php` (in case the backup predates a migration) and
`php bin/check-config.php`.

**Test the restore regularly** — restore last night's production backup into the *staging* database
(with staging's `DB_*` settings), then check the site. Step 9 of the plan requires a demonstrated restore.

## 5. Logs and errors

- Application log: `storage/logs/app-YYYY-MM-DD.log`, one JSON object per line: `ts`, `level`, `message`,
  `request_id`, `method`, `path`, `ip`, `user_id`, and `context` (exception class, file, trace).
- Users see a generic error page with a **reference** (the `request_id`). To investigate a report:
  `grep '"request_id":"<reference>"' storage/logs/app-*.log`
- Useful searches: `"level":"error"`, `Login rate limited`, `Email delivery failed`, `AI description failed`.
- Log level via `LOG_LEVEL` (default `info`). Rotate/delete old logs with the host's logrotate or a scheduled task
  (keep at least 30 days).
- **Monitoring:** point an uptime monitor (UptimeRobot, Better Stack...) at the home page, and ship
  `storage/logs/*.log` to a log service with an alert on `"level":"error"`. Tools such as Sentry can be added
  later by calling them from `App\ErrorHandler::handle()`.

## 6. Credentials and secrets

- Secrets live only in server environment variables / password manager — never in git, never in a zip sent to
  someone. `php bin/package.php` builds share/deploy zips from committed code only and rejects any package that
  contains `.env` files, uploads, logs, `.claude`/IDE folders or anything that looks like an API key.
- **Rotate now:** the original MVP zip contained `.env` files (including in a nested `.claude/worktree` copy). Treat every
  key and password in them as exposed: generate new AI provider keys (Groq/Gemini/OpenAI) and revoke the old ones, change the
  database password, and change any email/SMTP credentials.
- Rotate again whenever someone with access leaves the project, and use separate keys per environment so a
  leaked staging key never touches production.
- Stripe keys (Step 4): test-mode keys only until Darren signs off; live keys only ever in production env vars.
