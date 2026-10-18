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
    phone VARCHAR(32) NULL,
    phone_verified_at TIMESTAMP NULL,
    contact_preference ENUM('email', 'phone', 'both') NOT NULL DEFAULT 'email',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Listings
CREATE TABLE listings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    category VARCHAR(64) NOT NULL,
    item_condition VARCHAR(64) NOT NULL,
    price_daily DECIMAL(10, 2) NOT NULL,
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
    FULLTEXT idx_listings_search (title, final_description)
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
    status ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
    responded_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (renter_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_rental_requests_listing (listing_id),
    INDEX idx_rental_requests_renter (renter_id),
    INDEX idx_rental_requests_status (status)
) ENGINE=InnoDB;

-- Bookings (accepted requests; no payment in MVP)
CREATE TABLE bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rental_request_id INT UNSIGNED NOT NULL UNIQUE,
    listing_id INT UNSIGNED NOT NULL,
    renter_id INT UNSIGNED NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'approved_awaiting_payment',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (rental_request_id) REFERENCES rental_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
    FOREIGN KEY (renter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_bookings_owner (owner_id),
    INDEX idx_bookings_renter (renter_id),
    INDEX idx_bookings_listing_dates_status (listing_id, start_date, end_date, status)
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

INSERT INTO schema_migrations (version) VALUES
    ('2026_10_05_phase2_foundation'),
    ('2026_10_18_step1_accounts');
