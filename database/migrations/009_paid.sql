-- ============================================================
--  Migration 009 — full-payment stamp (for the "Mark as completed" receipt)
--    project_requests.paid_at : set when the owner confirms full payment while
--    marking a project completed. Used to show a "Paid" state and to send the
--    client a receipt email.
--
--  Run AFTER 008. In phpMyAdmin: select your database -> Import.
-- ============================================================

ALTER TABLE `project_requests`
  ADD COLUMN `paid_at` TIMESTAMP NULL DEFAULT NULL AFTER `completed_at`;
