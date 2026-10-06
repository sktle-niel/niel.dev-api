-- ============================================================
--  Migration 010 — generic rate-limit ledger
--    rate_hits : one row per throttled action, keyed by a salted hash
--    (an IP hash or an account hash). Powers per-IP throttling on the public
--    project tracker and per-account throttling on login (on top of the
--    existing per-IP login guard).
--
--  Run AFTER 009. In phpMyAdmin: select your database -> Import.
-- ============================================================

CREATE TABLE IF NOT EXISTS `rate_hits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket`     VARCHAR(32)     NOT NULL,   -- e.g. 'status', 'login-fail'
  `key_hash`   CHAR(64)        NOT NULL,   -- salted hash of the IP or account
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bucket_key_time` (`bucket`, `key_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
