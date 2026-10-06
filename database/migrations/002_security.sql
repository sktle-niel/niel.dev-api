-- ============================================================
--  Migration 002 — security hardening
--    login_attempts : per-IP login throttle (brute-force protection)
--    agents.ip_hash : salted hash of the signup IP (per-IP signup limit)
--
--  Run AFTER 001. In phpMyAdmin: select your database -> Import.
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`    CHAR(64)        NOT NULL,
  `success`    TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `agents`
  ADD COLUMN `ip_hash` CHAR(64) DEFAULT NULL AFTER `status`,
  ADD KEY `idx_agent_ip` (`ip_hash`);
