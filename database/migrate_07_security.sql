-- =====================================================================
-- UFMS migration 07: security rules
--   users.accepted_policy_at: when a Student/Staff member accepted the
--     Acceptable Use Policy (NULL = must accept before using the portal).
--   login_attempts: failed logins per IP for login throttling. A row with
--     blocked_until marks the failure that blocked the IP. Rows older than
--     1 day are pruned automatically by the application.
-- Run ONCE after migrate_06 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

ALTER TABLE `users`
  ADD COLUMN `accepted_policy_at` DATETIME DEFAULT NULL AFTER `otp_attempts`;

CREATE TABLE `login_attempts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `blocked_until` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`),
  KEY `idx_login_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
