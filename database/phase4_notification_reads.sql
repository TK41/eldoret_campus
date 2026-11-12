-- Run once on existing databases to add per-admin notification read state.
USE kimc_inventory;

CREATE TABLE IF NOT EXISTS notification_reads (
  admin_id  INT UNSIGNED NOT NULL,
  notif_id  INT UNSIGNED NOT NULL,
  read_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id, notif_id),
  INDEX idx_notification_reads_notif (notif_id),
  FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id) ON DELETE CASCADE,
  FOREIGN KEY (notif_id) REFERENCES notifications(notif_id) ON DELETE CASCADE
) ENGINE=InnoDB;
