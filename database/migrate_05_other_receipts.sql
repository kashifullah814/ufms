-- =====================================================================
-- UFMS migration 05: Grants (HEC/HED) and Student Related Charges -> RV
--   other_receipts: one row per receipt; each gets a receipt voucher.
--   A cancelled receipt keeps its row; its voucher is Cancelled.
-- Run ONCE after migrate_04 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

CREATE TABLE `other_receipts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `receipt_kind` ENUM('Grant','Student Charge') NOT NULL,
  `account_id` INT NOT NULL,
  `student_id` INT DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `receipt_date` DATE NOT NULL,
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_other_receipts_kind_date` (`receipt_kind`, `receipt_date`),
  KEY `idx_other_receipts_account` (`account_id`),
  KEY `idx_other_receipts_student` (`student_id`),
  KEY `fk_other_receipts_created_by` (`created_by`),
  CONSTRAINT `fk_other_receipts_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_other_receipts_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_other_receipts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_other_receipts_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_other_receipts_student` CHECK (`receipt_kind` = 'Student Charge' OR `student_id` IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration complete.
