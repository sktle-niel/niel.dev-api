-- ============================================================
--  Migration 005 — client downpayment
--    project_requests.downpayment : the downpayment the client proposes in the
--    website form (free text like custom_budget, e.g. "₱2,000"). The owner can
--    finalize/adjust it later in the admin deal editor. Nullable; existing rows
--    stay NULL.
--
--  Run AFTER 004. In phpMyAdmin: select your database -> Import.
-- ============================================================

ALTER TABLE `project_requests`
  ADD COLUMN `downpayment` VARCHAR(120) DEFAULT NULL AFTER `custom_budget`;
