-- RentNReturn Step 1 — accounts: email verification, password reset, rate limiting, session invalidation.
-- Back up the database and run on staging before production. Safe to re-run.
-- Apply with: php bin/migrate.php

-- Bumped on password change; sessions holding an older value are signed out.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'session_version') = 0,
    'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Single-use email verification / password reset tokens. Only SHA-256 hashes are stored.
CREATE TABLE IF NOT EXISTS user_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_tokens_hash (token_hash),
    INDEX idx_user_tokens_user_purpose (user_id, purpose),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Fixed-window rate limit counters (login, password reset, signup, AI requests). bucket is a SHA-256 hash.
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket CHAR(64) NOT NULL PRIMARY KEY,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at INT UNSIGNED NOT NULL,
    INDEX idx_rate_limits_reset (reset_at)
) ENGINE=InnoDB;

-- Existing MVP users have no verified email yet. They keep their accounts and listings, and are asked
-- to verify (a banner links to "resend") before creating/publishing listings or requesting/accepting rentals.

INSERT IGNORE INTO schema_migrations (version) VALUES ('2026_10_18_step1_accounts');
