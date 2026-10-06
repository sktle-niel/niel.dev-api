-- ============================================================
--  Migration 011 — password reset tokens
--    password_resets : single-use, short-lived tokens for the "forgot
--    password" flow (admin + agents). Mirrors the `sessions` table's
--    hash-only storage — the raw token never touches the database.
--
--  Run AFTER 010. In phpMyAdmin: select your database -> Import.
-- ============================================================

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`  ENUM('agent','admin') NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,        -- agents.id, or 0 for the single admin
  `token_hash` CHAR(64)        NOT NULL,         -- sha256 of the raw reset token
  `expires_at` TIMESTAMP       NOT NULL,
  `used_at`    TIMESTAMP       NULL DEFAULT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_token` (`token_hash`),
  KEY `idx_reset_user` (`user_type`, `user_id`),
  KEY `idx_reset_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
