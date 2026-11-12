-- Upgrade an existing KIMC inventory database for student equipment bookings.
-- Run once in phpMyAdmin after the core inventory, user, kit, and transaction tables exist.
USE kimc_inventory;

CREATE TABLE IF NOT EXISTS reservations (
  reservation_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_reference   VARCHAR(32) NOT NULL UNIQUE,
  access_token_hash   CHAR(64) NOT NULL,
  user_id             INT UNSIGNED NOT NULL,
  reservation_type    ENUM('single','kit') NOT NULL,
  asset_id            INT UNSIGNED NULL,
  kit_id              INT UNSIGNED NULL,
  requested_start     DATETIME NOT NULL,
  requested_end       DATETIME NOT NULL,
  purpose             VARCHAR(500) NOT NULL,
  status              ENUM('pending','approved','rejected','cancelled','expired','issued','returned')
                      NOT NULL DEFAULT 'pending',
  rejection_reason    VARCHAR(500) NULL,
  submitted_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decision_at         DATETIME NULL,
  decision_by         INT UNSIGNED NULL,
  issued_at           DATETIME NULL,
  issued_by           INT UNSIGNED NULL,
  cancelled_at        DATETIME NULL,
  returned_at         DATETIME NULL,
  INDEX idx_reservations_status_dates (status, requested_start, requested_end),
  INDEX idx_reservations_user_date (user_id, submitted_at),
  CONSTRAINT fk_reservation_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  CONSTRAINT fk_reservation_asset FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservation_kit FOREIGN KEY (kit_id) REFERENCES kits(kit_id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservation_decider FOREIGN KEY (decision_by) REFERENCES admin_users(admin_id) ON DELETE SET NULL,
  CONSTRAINT fk_reservation_issuer FOREIGN KEY (issued_by) REFERENCES admin_users(admin_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reservation_items (
  reservation_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id      INT UNSIGNED NOT NULL,
  asset_id            INT UNSIGNED NOT NULL,
  asset_code_snapshot VARCHAR(30) NOT NULL,
  asset_name_snapshot VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_reservation_asset (reservation_id, asset_id),
  INDEX idx_reservation_item_asset (asset_id),
  CONSTRAINT fk_reservation_item_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_reservation_item_asset
    FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS reservation_events (
  event_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT UNSIGNED NOT NULL,
  event_type     VARCHAR(32) NOT NULL,
  staff_id       INT UNSIGNED NULL,
  detail         VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reservation_events (reservation_id, created_at),
  CONSTRAINT fk_reservation_event_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE CASCADE,
  CONSTRAINT fk_reservation_event_staff
    FOREIGN KEY (staff_id) REFERENCES admin_users(admin_id) ON DELETE SET NULL
);

ALTER TABLE assets
  MODIFY COLUMN status ENUM('available','checked_out','reserved','maintenance','retired','missing')
    NOT NULL DEFAULT 'available';

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS kit_group_id VARCHAR(36) NULL,
  ADD COLUMN IF NOT EXISTS is_kit_txn TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS reservation_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS returned_by INT UNSIGNED NULL,
  MODIFY COLUMN condition_in ENUM('excellent','good','fair','damaged','missing') NULL,
  ADD INDEX IF NOT EXISTS idx_txn_reservation (reservation_id);

-- Foreign keys are separate so a failure here doesn't block the columns above.
-- If a constraint already exists you will get "Duplicate key name"; skip that statement.
ALTER TABLE transactions
  ADD CONSTRAINT fk_transaction_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE SET NULL;

ALTER TABLE transactions
  ADD CONSTRAINT fk_transaction_returned_by
    FOREIGN KEY (returned_by) REFERENCES admin_users(admin_id) ON DELETE SET NULL;
