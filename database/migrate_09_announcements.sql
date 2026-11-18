-- =====================================================================
-- UFMS migration 09: announcements
--   Notices written by the Admin. audience 'All' is shown on the portal
--   landing page and on every dashboard; 'Students' / 'Staff' /
--   'Accountants' only on that group's dashboard.
--   Shown from start_date until end_date (NULL = no end); pinned first.
-- Run ONCE after migrate_08 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

CREATE TABLE `announcements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `body` TEXT NOT NULL,
  `audience` ENUM('All','Students','Staff','Accountants') NOT NULL DEFAULT 'All',
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_announcements_active` (`audience`, `start_date`, `end_date`),
  KEY `fk_announcements_user` (`created_by`),
  CONSTRAINT `fk_announcements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_announcements_dates` CHECK (`end_date` IS NULL OR `end_date` >= `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
