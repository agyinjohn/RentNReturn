-- RentNReturn Step 2 — rental dates, pricing in pence, booking lifecycle and history.
-- Back up the database and run on staging before production. Safe to re-run: php bin/migrate.php

-- 1. Listing prices become integer pence (no floating-point money).
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'listings' AND column_name = 'price_daily_pence') = 0,
    'ALTER TABLE listings ADD COLUMN price_daily_pence INT UNSIGNED NOT NULL DEFAULT 0 AFTER item_condition',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'listings' AND column_name = 'price_daily') = 1,
    'UPDATE listings SET price_daily_pence = ROUND(price_daily * 100) WHERE price_daily_pence = 0',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'listings' AND column_name = 'price_daily') = 1,
    'ALTER TABLE listings DROP COLUMN price_daily',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill done: no default from here on, so a listing can never be saved without a price.
ALTER TABLE listings MODIFY COLUMN price_daily_pence INT UNSIGNED NOT NULL;

-- 2. Rental requests: more statuses, and the price quoted to the renter at request time.
ALTER TABLE rental_requests MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending';
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'rental_requests' AND column_name = 'price_daily_pence') = 0,
    'ALTER TABLE rental_requests ADD COLUMN price_daily_pence INT UNSIGNED NOT NULL DEFAULT 0 AFTER message',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'rental_requests' AND column_name = 'days') = 0,
    'ALTER TABLE rental_requests ADD COLUMN days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER price_daily_pence',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'rental_requests' AND column_name = 'total_pence') = 0,
    'ALTER TABLE rental_requests ADD COLUMN total_pence INT UNSIGNED NOT NULL DEFAULT 0 AFTER days',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing requests: quote at the listing's current price (inclusive day count).
UPDATE rental_requests r JOIN listings l ON l.id = r.listing_id
SET r.price_daily_pence = l.price_daily_pence,
    r.days = DATEDIFF(r.end_date, r.start_date) + 1,
    r.total_pence = (DATEDIFF(r.end_date, r.start_date) + 1) * l.price_daily_pence
WHERE r.days = 0;

-- 3. Bookings: pricing snapshot, payment deadline, confirmation time.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'price_daily_pence') = 0,
    'ALTER TABLE bookings ADD COLUMN price_daily_pence INT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'days') = 0,
    'ALTER TABLE bookings ADD COLUMN days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER price_daily_pence',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'total_pence') = 0,
    'ALTER TABLE bookings ADD COLUMN total_pence INT UNSIGNED NOT NULL DEFAULT 0 AFTER days',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'payment_due_at') = 0,
    'ALTER TABLE bookings ADD COLUMN payment_due_at DATETIME NULL AFTER total_pence',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'confirmed_at') = 0,
    'ALTER TABLE bookings ADD COLUMN confirmed_at DATETIME NULL AFTER payment_due_at',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE bookings b JOIN rental_requests r ON r.id = b.rental_request_id
SET b.price_daily_pence = r.price_daily_pence, b.days = r.days, b.total_pence = r.total_pence
WHERE b.days = 0;
-- Existing approvals keep payment_due_at NULL (no automatic expiry) — see docs/STEP_2_REPORT.md.
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'bookings' AND index_name = 'idx_bookings_status_due') = 0,
    'CREATE INDEX idx_bookings_status_due ON bookings (status, payment_due_at)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Booking history shared by renter and owner.
CREATE TABLE IF NOT EXISTS booking_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rental_request_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_role VARCHAR(16) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_booking_events_request (rental_request_id, created_at),
    FOREIGN KEY (rental_request_id) REFERENCES rental_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Reconstruct history for rentals created before this migration (only rentals with no events yet).
INSERT INTO booking_events (rental_request_id, booking_id, actor_user_id, actor_role, from_status, to_status, note, created_at)
SELECT r.id, NULL, r.renter_id, 'renter', NULL, 'requested', NULL, r.created_at
FROM rental_requests r
WHERE NOT EXISTS (SELECT 1 FROM booking_events e WHERE e.rental_request_id = r.id);

INSERT INTO booking_events (rental_request_id, booking_id, actor_user_id, actor_role, from_status, to_status, note, created_at)
SELECT r.id, b.id, l.user_id, 'owner', 'pending', IF(b.id IS NULL, r.status, 'approved_awaiting_payment'), 'Recorded before booking history existed', COALESCE(r.responded_at, r.updated_at)
FROM rental_requests r
JOIN listings l ON l.id = r.listing_id
LEFT JOIN bookings b ON b.rental_request_id = r.id
WHERE r.status IN ('accepted', 'declined')
  AND (SELECT COUNT(*) FROM booking_events e WHERE e.rental_request_id = r.id) = 1;

-- 5. Integrity constraints. Enforced by MySQL 8.0.16+ and MariaDB 10.2+; older servers do not support
--    CHECK constraints, so they are skipped there (the application enforces the same rules).
SET @has_checks = (SELECT COUNT(*) FROM information_schema.tables
                   WHERE table_schema = 'information_schema' AND table_name = 'CHECK_CONSTRAINTS');
SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_listings_price'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE listings ADD CONSTRAINT chk_listings_price CHECK (price_daily_pence > 0)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_listings_window'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE listings ADD CONSTRAINT chk_listings_window CHECK (available_from IS NULL OR available_until IS NULL OR available_until >= available_from)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_requests_dates'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE rental_requests ADD CONSTRAINT chk_requests_dates CHECK (end_date >= start_date)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_requests_status'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE rental_requests ADD CONSTRAINT chk_requests_status CHECK (status IN (''pending'',''accepted'',''declined'',''cancelled'',''expired''))');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_requests_total'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE rental_requests ADD CONSTRAINT chk_requests_total CHECK (total_pence = days * price_daily_pence)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_bookings_dates'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE bookings ADD CONSTRAINT chk_bookings_dates CHECK (end_date >= start_date)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_bookings_status'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE bookings ADD CONSTRAINT chk_bookings_status CHECK (status IN (''approved_awaiting_payment'',''confirmed'',''handover_ready'',''in_rental'',''return_pending'',''completed'',''cancelled'',''expired'',''refunded'',''disputed''))');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists = 0;
SET @sql = IF(@has_checks = 0, 'DO 0',
    'SET @exists = (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE constraint_schema = DATABASE() AND constraint_name = ''chk_bookings_total'')');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@has_checks = 0 OR @exists > 0, 'DO 0', 'ALTER TABLE bookings ADD CONSTRAINT chk_bookings_total CHECK (total_pence = days * price_daily_pence)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO schema_migrations (version) VALUES ('2026_10_18_step2_bookings');
