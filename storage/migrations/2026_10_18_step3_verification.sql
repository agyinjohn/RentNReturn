-- RentNReturn Step 3 — mobile verification, identity verification status, public display names.
-- Back up the database and run on staging before production. Safe to re-run: php bin/migrate.php

-- Public name shown on listings/profiles instead of the legal name (falls back to the first name).
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'display_name') = 0,
    'ALTER TABLE users ADD COLUMN display_name VARCHAR(60) NULL AFTER name',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Set only by a trusted identity-provider result; cleared if the provider withdraws it.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'identity_verified_at') = 0,
    'ALTER TABLE users ADD COLUMN identity_verified_at DATETIME NULL AFTER phone_verified_at',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.phone holds only a VERIFIED number (E.164), and one number can verify only one account.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uq_users_phone') = 0,
    'ALTER TABLE users ADD UNIQUE KEY uq_users_phone (phone)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One-time SMS codes. Only a bcrypt hash of the code is stored.
CREATE TABLE IF NOT EXISTS phone_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    phone VARCHAR(20) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone_verifications_user (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Verification history (phone, identity): provider, status and reference only — never document images or ID numbers.
CREATE TABLE IF NOT EXISTS user_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(16) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL,
    provider_reference VARCHAR(255) NULL,
    detail VARCHAR(255) NULL,
    verified_at DATETIME NULL,
    failed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_verifications_user (user_id, type, created_at),
    INDEX idx_user_verifications_reference (provider, provider_reference),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (version) VALUES ('2026_10_18_step3_verification');
