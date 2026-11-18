-- =====================================================================
-- UFMS migration 02: Fee collection -> receipt vouchers (RV)
--   fees_records: head-wise split of each new challan (NULL = old challan)
--   fee_payments: one row per installment (each gets its own RV)
-- Run ONCE after migrate_01 (take a mysqldump backup first). MariaDB 10.4.
-- Existing challans are NOT changed here; database/backfill_vouchers.php creates
-- their payment rows and vouchers (dry run first).
-- =====================================================================

USE `university_finance_db`;

ALTER TABLE `fees_records`
  ADD COLUMN `tuition_amt` DECIMAL(10,2) DEFAULT NULL AFTER `total_fee`,
  ADD COLUMN `admission_amt` DECIMAL(10,2) DEFAULT NULL AFTER `tuition_amt`,
  ADD COLUMN `exam_amt` DECIMAL(10,2) DEFAULT NULL AFTER `admission_amt`,
  ADD COLUMN `other_amt` DECIMAL(10,2) DEFAULT NULL AFTER `exam_amt`,
  ADD CONSTRAINT `chk_fees_split` CHECK (
    (`tuition_amt` IS NULL AND `admission_amt` IS NULL AND `exam_amt` IS NULL AND `other_amt` IS NULL)
    OR (`tuition_amt` >= 0 AND `admission_amt` >= 0 AND `exam_amt` >= 0 AND `other_amt` >= 0
        AND `tuition_amt` + `admission_amt` + `exam_amt` + `other_amt` = `total_fee`));

-- ---------------------------------------------------------------------
-- fee_payments: har installment ki alag row (voucher source = fee_payment)
-- fees_records.amount_paid = in rows ka total (same transaction mein update hota hai)
-- ---------------------------------------------------------------------
CREATE TABLE `fee_payments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fee_record_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `received_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fee_payments_record` (`fee_record_id`),
  KEY `idx_fee_payments_date` (`payment_date`),
  KEY `fk_fee_payments_received_by` (`received_by`),
  CONSTRAINT `fk_fee_payments_record` FOREIGN KEY (`fee_record_id`) REFERENCES `fees_records` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_fee_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fee_payments_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migration complete.
