-- ============================================================
--  Migration 008 — project completion date
--    project_requests.completed_at : set the moment progress first reaches
--    100% (cleared if it drops back below). Powers the green "project
--    completed" mark on the visitor heatmap.
--
--  Run AFTER 007. In phpMyAdmin: select your database -> Import.
-- ============================================================

ALTER TABLE `project_requests`
  ADD COLUMN `completed_at` TIMESTAMP NULL DEFAULT NULL AFTER `notified_at`;
