-- ============================================================
--  Migration 012 — portfolio ("Selected Work") management
--    works : admin-managed portfolio items shown on the landing page.
--    The admin pastes a link, names it, and uploads a screenshot; there's
--    no headless-browser capture on shared hosting, so the image is
--    admin-supplied rather than auto-generated.
--
--  Run AFTER 011. In phpMyAdmin: select your database -> Import.
-- ============================================================

CREATE TABLE IF NOT EXISTS `works` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(160)    NOT NULL,
  `url`         VARCHAR(500)    NOT NULL,
  `category`    VARCHAR(160)    DEFAULT NULL,
  `description` TEXT            DEFAULT NULL,
  `image`       VARCHAR(255)    NOT NULL,
  `featured`    TINYINT(1)      NOT NULL DEFAULT 0,
  `enabled`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
