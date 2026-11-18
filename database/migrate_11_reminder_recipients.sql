-- =====================================================================
-- UFMS migration 11: per-recipient delivery record for reminders
--   reminder_recipients: one row per user a reminder was sent to
--   (snapshot at the time of sending; manual sending only, no cron).
--   status    'None'   = dashboard only (email not requested)
--             'Sent'   = email accepted by the mail server
--             'Failed' = email failed (see error); can be retried
--   sent_at   when the email was sent (last successful try)
--   seen_at   when the user first saw the reminder on their portal
--   Rows are deleted with the reminder or with the user (CASCADE).
--   Existing reminders keep working; they simply have no rows here.
-- Run ONCE after migrate_10 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

CREATE TABLE `reminder_recipients` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `reminder_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `status` ENUM('None','Sent','Failed') NOT NULL DEFAULT 'None',
  `error` VARCHAR(255) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `seen_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reminder_recipient` (`reminder_id`, `user_id`),
  KEY `idx_reminder_recipients_user` (`user_id`, `created_at`),
  KEY `idx_reminder_recipients_status` (`reminder_id`, `status`),
  CONSTRAINT `fk_reminder_recipients_reminder` FOREIGN KEY (`reminder_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reminder_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
