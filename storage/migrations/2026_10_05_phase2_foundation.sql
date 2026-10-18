-- RentNReturn Phase 2 foundation migration
-- Review/back up before applying to an existing database.

ALTER TABLE listings
    ADD UNIQUE KEY uq_listings_slug (slug);

-- Existing accepted MVP bookings are approvals awaiting a future payment integration.
UPDATE bookings SET status = 'approved_awaiting_payment' WHERE status = 'accepted';

CREATE INDEX idx_bookings_listing_dates_status
    ON bookings (listing_id, start_date, end_date, status);

ALTER TABLE users
    ADD COLUMN email_verified_at TIMESTAMP NULL AFTER email,
    ADD COLUMN phone VARCHAR(32) NULL AFTER name,
    ADD COLUMN phone_verified_at TIMESTAMP NULL AFTER phone;
