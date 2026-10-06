-- ============================================================
--  Migration 007 — project progress tracking + update images
--    project_requests.progress      : 0-100 % complete (owner sets it)
--    project_requests.progress_note : optional update message for the client
--    project_requests.notified_at   : when the "90% — ready for payment" email
--                                     was sent to the client
--    project_images                 : up to 5 progress photos per request
--
--  Powers the public tracker: a client enters their reference on /track and
--  sees the progress bar, note, and images.
--
--  Run AFTER 006. In phpMyAdmin: select your database -> Import.
-- ============================================================

ALTER TABLE `project_requests`
  ADD COLUMN `progress`      TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `commission_pct`,
  ADD COLUMN `progress_note` VARCHAR(500)     DEFAULT NULL       AFTER `progress`,
  ADD COLUMN `notified_at`   TIMESTAMP        NULL DEFAULT NULL  AFTER `progress_note`;

CREATE TABLE IF NOT EXISTS `project_images` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `path`       VARCHAR(255)    NOT NULL,      -- e.g. /uploads/ab12....webp
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_req` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
