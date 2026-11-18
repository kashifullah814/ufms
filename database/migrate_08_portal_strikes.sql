-- =====================================================================
-- UFMS migration 08: wrong-portal strike rule
--   portal_strikes: one row per wrong-portal login (correct password,
--     wrong role). Counted over a rolling 24 hours; 3 strikes block the
--     account for 24 hours. A user's rows are deleted when the block is
--     applied or when the Admin unlocks the account (strike reset);
--     rows older than 2 days are pruned by the application.
--   users.lock_reason: why locked_until is set. 'WRONG_PORTAL' = blocked
--     by this rule (listed on the Admin dashboard, not cleared by a
--     password reset). NULL = other locks (wrong password, auto-lock).
-- Run ONCE after migrate_07 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

ALTER TABLE `users`
  ADD COLUMN `lock_reason` VARCHAR(30) DEFAULT NULL AFTER `locked_until`;

CREATE TABLE `portal_strikes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `portal` VARCHAR(20) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_portal_strikes_user_time` (`user_id`, `created_at`),
  CONSTRAINT `fk_portal_strikes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
