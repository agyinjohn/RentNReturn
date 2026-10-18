-- RentNReturn Phase 2 foundation migration
-- Brings an existing MVP database up to the state of storage/schema.sql.
-- Back up the database and run on staging before production.
--
-- Safe to re-run: every step checks the current structure first, and a fresh
-- install from storage/schema.sql is already at this version (the steps no-op).
-- Compatible with MySQL 5.7+ and MariaDB (no ADD COLUMN/INDEX IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 1. Unique listing slugs.
-- Existing duplicates would make the unique key fail, so keep the oldest listing's
-- slug and suffix the others with their id. Old URLs for the renamed duplicates change.
UPDATE listings l
JOIN (
    SELECT slug, MIN(id) AS keep_id
    FROM listings
    GROUP BY slug
    HAVING COUNT(*) > 1
) d ON d.slug = l.slug
SET l.slug = CONCAT(LEFT(l.slug, 230), '-', l.id)
WHERE l.id <> d.keep_id;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'listings' AND index_name = 'uq_listings_slug') = 0,
    'ALTER TABLE listings ADD UNIQUE KEY uq_listings_slug (slug)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Booking status.
-- The MVP column may be an ENUM without 'approved_awaiting_payment', which would make
-- the UPDATE below fail in strict mode. Widen it to the VARCHAR used by schema.sql first.
ALTER TABLE bookings
    MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'approved_awaiting_payment';

-- Existing accepted MVP bookings are approvals awaiting a future payment integration.
UPDATE bookings SET status = 'approved_awaiting_payment' WHERE status = 'accepted';

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'bookings' AND index_name = 'idx_bookings_listing_dates_status') = 0,
    'CREATE INDEX idx_bookings_listing_dates_status ON bookings (listing_id, start_date, end_date, status)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Verification groundwork columns on users.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'email_verified_at') = 0,
    'ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL AFTER email',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone') = 0,
    'ALTER TABLE users ADD COLUMN phone VARCHAR(32) NULL AFTER name',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'phone_verified_at') = 0,
    'ALTER TABLE users ADD COLUMN phone_verified_at TIMESTAMP NULL AFTER phone',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO schema_migrations (version) VALUES ('2026_10_05_phase2_foundation');
