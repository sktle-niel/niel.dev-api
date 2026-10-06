-- ============================================================
--  Migration 001 — agents, referral attribution, sessions
--
--  Run this on an EXISTING codekathax database that already has the
--  original `project_requests` table. It adds the agent/referral feature
--  without touching existing rows. Safe to run once.
--
--  In phpMyAdmin: select your database -> Import -> choose this file.
-- ============================================================

CREATE TABLE IF NOT EXISTS `agents` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120)    NOT NULL,
  `email`         VARCHAR(160)    NOT NULL,
  `phone`         VARCHAR(60)     DEFAULT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `payout_method` VARCHAR(40)     DEFAULT NULL,
  `payout_number` VARCHAR(120)    DEFAULT NULL,
  `ref_token`     VARCHAR(20)     NOT NULL,
  `status`        ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at`   TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_email` (`email`),
  UNIQUE KEY `uq_ref_token` (`ref_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sessions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`  ENUM('agent','admin') NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)        NOT NULL,
  `expires_at` TIMESTAMP       NOT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `project_requests`
  ADD COLUMN `agent_id`    BIGINT UNSIGNED DEFAULT NULL AFTER `org`,
  ADD COLUMN `ref_token`   VARCHAR(20)     DEFAULT NULL AFTER `agent_id`,
  ADD COLUMN `deal_amount` DECIMAL(10,2)   DEFAULT NULL AFTER `ref_token`,
  ADD COLUMN `deal_status` ENUM('lead','won','lost') NOT NULL DEFAULT 'lead' AFTER `deal_amount`,
  ADD KEY `idx_agent` (`agent_id`);
