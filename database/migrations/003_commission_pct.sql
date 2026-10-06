-- ============================================================
--  Migration 003 — per-client commission percentage
--    commission_pct : the agent's share for THIS client (0-30%, default 15%).
--    Set by the admin per deal; capped at 30% in the API.
--
--  Run AFTER 002. In phpMyAdmin: select your database -> Import.
-- ============================================================

ALTER TABLE `project_requests`
  ADD COLUMN `commission_pct` TINYINT UNSIGNED NOT NULL DEFAULT 15 AFTER `deal_status`;
