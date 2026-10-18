-- RentNReturn Step 4 — Stripe Connect payments: payments, ledger, webhooks, refunds, payouts.
-- Back up the database and run on staging before production. Safe to re-run: php bin/migrate.php

-- Renter's Stripe customer (card details live at Stripe, never here).
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'stripe_customer_id') = 0,
    'ALTER TABLE users ADD COLUMN stripe_customer_id VARCHAR(255) NULL AFTER identity_verified_at',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Owner's Stripe Connect account and its onboarding/KYC/payout capability. Separate from the public badge.
CREATE TABLE IF NOT EXISTS connected_accounts (
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
CREATE TABLE IF NOT EXISTS payments (
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
CREATE TABLE IF NOT EXISTS payment_ledger (
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
CREATE TABLE IF NOT EXISTS webhook_events (
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

CREATE TABLE IF NOT EXISTS refunds (
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
CREATE TABLE IF NOT EXISTS payouts (
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

INSERT IGNORE INTO schema_migrations (version) VALUES ('2026_10_18_step4_payments');
