-- RentNReturn — full schema for a fresh install.
-- Preferred: php bin/migrate.php (creates tables from this file on an empty database, then applies migrations).
-- Manual alternative: import this file in phpMyAdmin/mysql. Existing databases: use migrations instead.

CREATE DATABASE IF NOT EXISTS p2p_rent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE p2p_rent;

-- Users (auth + minimal profile)
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password_hash VARCHAR(255) NOT NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(255) NOT NULL,
    -- Public name shown on listings/profiles instead of the legal name (falls back to the first name).
    display_name VARCHAR(60) NULL,
    -- Verified mobile only (E.164). One number per account.
    phone VARCHAR(32) NULL,
    phone_verified_at TIMESTAMP NULL,
    -- Set only by a trusted identity-provider result.
    identity_verified_at DATETIME NULL,
    -- Renter's Stripe customer (card details live at Stripe, never here).
    stripe_customer_id VARCHAR(255) NULL,
    contact_preference ENUM('email', 'phone', 'both') NOT NULL DEFAULT 'email',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB;

-- Listings
CREATE TABLE listings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    category VARCHAR(64) NOT NULL,
    item_condition VARCHAR(64) NOT NULL,
    price_daily_pence INT UNSIGNED NOT NULL,
    ai_suggested_description TEXT NULL,
    final_description TEXT NOT NULL,
    ai_detected_attributes JSON NULL,
    available_now TINYINT(1) NOT NULL DEFAULT 1,
    available_from DATE NULL,
    available_until DATE NULL,
    published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_listings_published (published),
    INDEX idx_listings_category (category),
    UNIQUE KEY uq_listings_slug (slug),
    FULLTEXT idx_listings_search (title, final_description),
    CONSTRAINT chk_listings_price CHECK (price_daily_pence > 0),
    CONSTRAINT chk_listings_window CHECK (available_from IS NULL OR available_until IS NULL OR available_until >= available_from)
) ENGINE=InnoDB;

-- Listing images (2-5 per listing)
CREATE TABLE listing_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(512) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    INDEX idx_listing_images_listing (listing_id)
) ENGINE=InnoDB;

-- Rental requests (before acceptance)
CREATE TABLE rental_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id INT UNSIGNED NOT NULL,
    renter_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    message TEXT NULL,
    -- Price quoted at request time, in pence. days counts both start and end dates.
    price_daily_pence INT UNSIGNED NOT NULL DEFAULT 0,
    days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_pence INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    responded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (renter_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_rental_requests_listing (listing_id),
    INDEX idx_rental_requests_renter (renter_id),
    INDEX idx_rental_requests_status (status),
    CONSTRAINT chk_requests_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_requests_status CHECK (status IN ('pending','accepted','declined','cancelled','expired')),
    CONSTRAINT chk_requests_total CHECK (total_pence = days * price_daily_pence)
) ENGINE=InnoDB;

-- Bookings (approved requests). Lifecycle in src/Booking/BookingStatus.php.
CREATE TABLE bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rental_request_id INT UNSIGNED NOT NULL UNIQUE,
    listing_id INT UNSIGNED NOT NULL,
    renter_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'approved_awaiting_payment',
    price_daily_pence INT UNSIGNED NOT NULL DEFAULT 0,
    days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_pence INT UNSIGNED NOT NULL DEFAULT 0,
    payment_due_at DATETIME NULL,
    confirmed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rental_request_id) REFERENCES rental_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (renter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_bookings_owner (owner_id),
    INDEX idx_bookings_renter (renter_id),
    INDEX idx_bookings_listing_dates_status (listing_id, start_date, end_date, status),
    INDEX idx_bookings_status_due (status, payment_due_at),
    CONSTRAINT chk_bookings_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_bookings_status CHECK (status IN ('approved_awaiting_payment','confirmed','handover_ready','in_rental','return_pending','completed','cancelled','expired','refunded','disputed')),
    CONSTRAINT chk_bookings_total CHECK (total_pence = days * price_daily_pence)
) ENGINE=InnoDB;

-- Booking history shared by renter and owner.
CREATE TABLE booking_events (
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

-- Applied migrations. A fresh install from this file already includes the ones below.
CREATE TABLE schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Single-use email verification / password reset tokens. Only SHA-256 hashes are stored.
CREATE TABLE user_tokens (
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
CREATE TABLE rate_limits (
    bucket CHAR(64) NOT NULL PRIMARY KEY,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at INT UNSIGNED NOT NULL,
    INDEX idx_rate_limits_reset (reset_at)
) ENGINE=InnoDB;

-- One-time SMS codes. Only a bcrypt hash of the code is stored.
CREATE TABLE phone_verifications (
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
CREATE TABLE user_verifications (
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

-- Owner's Stripe Connect account and its onboarding/KYC/payout capability. Separate from the public badge.
CREATE TABLE connected_accounts (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    stripe_account_id VARCHAR(255) NOT NULL,
    details_submitted TINYINT(1) NOT NULL DEFAULT 0,
    charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
    payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
    requirements_due VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_connected_accounts_stripe (stripe_account_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per payment attempt (Checkout Session). Amounts in pence, always computed on the server.
CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    provider VARCHAR(16) NOT NULL DEFAULT 'stripe',
    checkout_session_id VARCHAR(255) NULL,
    checkout_url VARCHAR(1000) NULL,
    payment_intent_id VARCHAR(255) NULL,
    charge_id VARCHAR(255) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'gbp',
    rental_pence INT UNSIGNED NOT NULL,
    platform_fee_pence INT UNSIGNED NOT NULL,
    amount_pence INT UNSIGNED NOT NULL,
    owner_amount_pence INT UNSIGNED NOT NULL,
    refunded_pence INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(24) NOT NULL DEFAULT 'created',
    failure_reason VARCHAR(255) NULL,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_session (checkout_session_id),
    UNIQUE KEY uq_payments_intent (payment_intent_id),
    INDEX idx_payments_booking (booking_id, status),
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Append-only record of every money movement. The application only ever INSERTs here.
CREATE TABLE payment_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NOT NULL,
    entry_type VARCHAR(24) NOT NULL,
    amount_pence INT NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'gbp',
    provider_reference VARCHAR(255) NULL,
    actor VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_ledger_booking (booking_id, id),
    UNIQUE KEY uq_payment_ledger_ref (entry_type, provider_reference),
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Every Stripe webhook event we receive. UNIQUE (provider, event_id) makes retries harmless.
CREATE TABLE webhook_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(16) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    object_id VARCHAR(255) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'received',
    error VARCHAR(1000) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_webhook_events (provider, event_id),
    INDEX idx_webhook_events_status (status, received_at)
) ENGINE=InnoDB;

CREATE TABLE refunds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    booking_id INT UNSIGNED NOT NULL,
    amount_pence INT UNSIGNED NOT NULL,
    stripe_refund_id VARCHAR(255) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    reason VARCHAR(255) NOT NULL,
    actor VARCHAR(64) NOT NULL,
    failure_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_refunds_stripe (stripe_refund_id),
    INDEX idx_refunds_payment (payment_id),
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- The owner's share of a paid booking, transferred to their connected account when released.
CREATE TABLE payouts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    amount_pence INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'gbp',
    status VARCHAR(24) NOT NULL DEFAULT 'pending',
    stripe_transfer_id VARCHAR(255) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    destination_account_id VARCHAR(255) NULL,
    note VARCHAR(255) NULL,
    released_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payouts_booking (booking_id),
    UNIQUE KEY uq_payouts_transfer (stripe_transfer_id),
    INDEX idx_payouts_status (status),
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT INTO schema_migrations (version) VALUES
    ('2026_10_05_phase2_foundation'),
    ('2026_10_18_step1_accounts'),
    ('2026_10_18_step2_bookings'),
    ('2026_10_18_step3_verification'),
    ('2026_10_18_step4_payments');
