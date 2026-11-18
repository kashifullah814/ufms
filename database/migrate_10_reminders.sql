-- =====================================================================
-- UFMS migration 10: Reminders (fee reminders + general notices)
--   The announcements table (and its rows) stays; new columns only.
--   type          'General' = notice for All / Students / Staff / Accountants (as before)
--                 'Fee'     = fee reminder for students WITH pending dues
--   fee_scope     'All' (every student with dues) | 'Filter' (program and/or
--                 semester) | 'Student' (one student). NULL for General.
--   due_date      shown to the student ({due_date}); required for Fee.
--   end_date      = "show until" (unchanged). start_date = date sent.
--   email_requested / recipients_count / emails_sent / emails_failed:
--                 delivery record shown in the history table.
--   A reminder for one student is deleted with that student (CASCADE).
-- Run ONCE after migrate_09 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

ALTER TABLE `announcements`
  ADD COLUMN `type` ENUM('General','Fee') NOT NULL DEFAULT 'General' AFTER `id`,
  ADD COLUMN `fee_scope` ENUM('All','Filter','Student') DEFAULT NULL AFTER `audience`,
  ADD COLUMN `target_program_id` INT DEFAULT NULL AFTER `fee_scope`,
  ADD COLUMN `target_semester` TINYINT DEFAULT NULL AFTER `target_program_id`,
  ADD COLUMN `target_student_id` INT DEFAULT NULL AFTER `target_semester`,
  ADD COLUMN `due_date` DATE DEFAULT NULL AFTER `end_date`,
  ADD COLUMN `email_requested` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_pinned`,
  ADD COLUMN `recipients_count` INT DEFAULT NULL AFTER `email_requested`,
  ADD COLUMN `emails_sent` INT NOT NULL DEFAULT 0 AFTER `recipients_count`,
  ADD COLUMN `emails_failed` INT NOT NULL DEFAULT 0 AFTER `emails_sent`,
  ADD KEY `idx_announcements_type` (`type`, `start_date`, `end_date`),
  ADD KEY `fk_announcements_program` (`target_program_id`),
  ADD KEY `fk_announcements_student` (`target_student_id`),
  ADD CONSTRAINT `fk_announcements_program` FOREIGN KEY (`target_program_id`) REFERENCES `fee_structure` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_announcements_student` FOREIGN KEY (`target_student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chk_announcements_type` CHECK (
        (`type` = 'General' AND `fee_scope` IS NULL)
     OR (`type` = 'Fee' AND `fee_scope` IS NOT NULL AND `due_date` IS NOT NULL AND `audience` = 'Students'
         AND (`fee_scope` <> 'Student' OR `target_student_id` IS NOT NULL)));
