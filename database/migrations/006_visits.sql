-- ============================================================
--  Migration 006 — visitor activity heatmap
--    visits : one row per unique visitor per day. Deduped by a salted
--    IP+day hash, so a raw IP is never stored and reloads never inflate the
--    count. Powers the GitHub-style visitor contribution graph on the landing
--    page (each square = a day, greener = more visitors).
--
--  Run AFTER 005. In phpMyAdmin: select your database -> Import.
-- ============================================================

CREATE TABLE IF NOT EXISTS `visits` (
  `day`          DATE      NOT NULL,
  `visitor_hash` CHAR(64)  NOT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`day`, `visitor_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
