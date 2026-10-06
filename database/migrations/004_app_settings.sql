-- ============================================================
--  Migration 004 — editable app settings (key/value)
--    agent_limit : max number of agents that can apply (0 = unlimited).
--    Editable by the admin from the Settings view.
--
--  Run AFTER 003. In phpMyAdmin: select your database -> Import.
-- ============================================================

CREATE TABLE IF NOT EXISTS `app_settings` (
  `name`  VARCHAR(64)  NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`name`, `value`) VALUES ('agent_limit', '0')
  ON DUPLICATE KEY UPDATE `name` = `name`;
