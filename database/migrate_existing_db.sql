-- =====================================================================
-- UFMS - MIGRATION: purane database ko naye (fixed) structure mein badalta hai.
-- Data delete nahi hota. Jo records kharab/orphan thay woh "backup_*" tables
-- mein move ho jate hain.
--
-- KAISE CHALAYEIN:
--   1) phpMyAdmin > university_finance_db > Export  (pehle backup lazmi lein!)
--   2) phpMyAdmin > university_finance_db > Import > yeh file > Go
--   Sirf EK dafa chalayein.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1) MyISAM -> InnoDB (transactions aur foreign keys ke liye zaroori)
-- ---------------------------------------------------------------------
ALTER TABLE `users`          ENGINE = InnoDB;
ALTER TABLE `students`       ENGINE = InnoDB;
ALTER TABLE `fee_structure`  ENGINE = InnoDB;
ALTER TABLE `fees_records`   ENGINE = InnoDB;
ALTER TABLE `staff_salaries` ENGINE = InnoDB;
ALTER TABLE `expenses`       ENGINE = InnoDB;

-- ---------------------------------------------------------------------
-- 2) users: security columns
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  MODIFY `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  MODIFY `can_manage_fees` TINYINT(1) NOT NULL DEFAULT 0,
  MODIFY `can_manage_salaries` TINYINT(1) NOT NULL DEFAULT 0,
  MODIFY `can_manage_expenses` TINYINT(1) NOT NULL DEFAULT 0,
  MODIFY `reset_otp` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_manage_expenses`,
  ADD COLUMN `failed_logins` INT NOT NULL DEFAULT 0 AFTER `must_change_password`,
  ADD COLUMN `locked_until` DATETIME DEFAULT NULL AFTER `failed_logins`,
  ADD COLUMN `otp_attempts` INT NOT NULL DEFAULT 0 AFTER `otp_expiry`,
  ADD KEY `idx_users_role` (`role`);

-- Purane OTP plain text mein thay - saaf kar dein
UPDATE `users` SET `reset_otp` = NULL, `otp_expiry` = NULL, `otp_attempts` = 0;

-- Students/Staff ka purana password unka Reg No / Staff ID tha (asaani se guess ho jata hai)
UPDATE `users` SET `must_change_password` = 1 WHERE `role` IN ('Student', 'Staff');

-- Jin accounts ka password bilkul same hai (Admin samet) - sab ko naya password rakhna hoga
UPDATE `users` u
JOIN (SELECT `password` FROM `users` GROUP BY `password` HAVING COUNT(*) > 1) d ON d.`password` = u.`password`
SET u.`must_change_password` = 1;

-- ---------------------------------------------------------------------
-- 3) fee_structure
-- ---------------------------------------------------------------------
UPDATE `fee_structure` SET `program_name` = TRIM(`program_name`);
UPDATE `fee_structure` SET `program_name` = 'Software Engineering' WHERE LOWER(`program_name`) = 'software engineering';
UPDATE `fee_structure` SET
  `tuition_fee` = COALESCE(`tuition_fee`, 0), `admission_fee` = COALESCE(`admission_fee`, 0),
  `exam_fee` = COALESCE(`exam_fee`, 0), `other_charges` = COALESCE(`other_charges`, 0);
ALTER TABLE `fee_structure`
  MODIFY `tuition_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  MODIFY `admission_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  MODIFY `exam_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  MODIFY `other_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ADD UNIQUE KEY `uq_fee_structure_program` (`program_name`),
  ADD CONSTRAINT `chk_fee_structure_positive` CHECK (`tuition_fee` >= 0 AND `admission_fee` >= 0 AND `exam_fee` >= 0 AND `other_charges` >= 0);

-- ---------------------------------------------------------------------
-- 4) students: department (text) -> program_id (fee_structure se link)
-- ---------------------------------------------------------------------
-- Jo students kisi user account se linked nahi - backup mein
CREATE TABLE `backup_orphan_students` AS
  SELECT s.* FROM `students` s LEFT JOIN `users` u ON u.`id` = s.`user_id` WHERE u.`id` IS NULL;
DELETE s FROM `students` s LEFT JOIN `users` u ON u.`id` = s.`user_id` WHERE u.`id` IS NULL;

-- Jis department ka fee structure nahi, us ka program (0 fee ke saath) bana dein - Admin baad mein fee set kare
INSERT INTO `fee_structure` (`program_name`)
SELECT DISTINCT TRIM(s.`department`) FROM `students` s
WHERE s.`department` IS NOT NULL AND TRIM(s.`department`) <> ''
  AND NOT EXISTS (SELECT 1 FROM `fee_structure` f WHERE LOWER(f.`program_name`) = LOWER(TRIM(s.`department`)));

ALTER TABLE `students` ADD COLUMN `program_id` INT DEFAULT NULL AFTER `reg_number`;
UPDATE `students` s JOIN `fee_structure` f ON LOWER(f.`program_name`) = LOWER(TRIM(s.`department`))
SET s.`program_id` = f.`id`;

UPDATE `students` SET `semester` = 1 WHERE `semester` IS NULL OR `semester` < 1 OR `semester` > 8;
UPDATE `students` SET `scholarship_discount` = 0 WHERE `scholarship_discount` IS NULL OR `scholarship_discount` < 0;

ALTER TABLE `students`
  DROP COLUMN `department`,
  DROP KEY `user_id`,
  MODIFY `user_id` INT NOT NULL,
  MODIFY `semester` TINYINT NOT NULL DEFAULT 1,
  MODIFY `scholarship_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ADD UNIQUE KEY `uq_students_user` (`user_id`),
  ADD KEY `idx_students_program` (`program_id`),
  ADD CONSTRAINT `chk_students_semester` CHECK (`semester` BETWEEN 1 AND 8),
  ADD CONSTRAINT `chk_students_discount` CHECK (`scholarship_discount` >= 0);

-- ---------------------------------------------------------------------
-- 5) fees_records: sahi columns
--    Purana code: amount_due = jo PAID hua, late_fee_fine = jo BAQI raha.
--    Naya:  total_fee, amount_paid, late_fee_fine (asal jurmana), balance (auto)
-- ---------------------------------------------------------------------
-- Jin records ka student exist nahi karta - backup mein
CREATE TABLE `backup_orphan_fees_records` AS
  SELECT f.* FROM `fees_records` f LEFT JOIN `students` s ON s.`id` = f.`student_id` WHERE s.`id` IS NULL;
DELETE f FROM `fees_records` f LEFT JOIN `students` s ON s.`id` = f.`student_id` WHERE s.`id` IS NULL;

ALTER TABLE `fees_records`
  ADD COLUMN `semester_no` TINYINT DEFAULT NULL AFTER `challan_number`,
  ADD COLUMN `total_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `semester_no`,
  ADD COLUMN `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `late_fee_fine`,
  ADD COLUMN `collected_by` INT DEFAULT NULL AFTER `payment_date`,
  ADD COLUMN `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER `collected_by`;

UPDATE `fees_records` SET
  `total_fee`     = COALESCE(`amount_due`, 0) + COALESCE(`late_fee_fine`, 0),
  `amount_paid`   = COALESCE(`amount_due`, 0),
  `late_fee_fine` = 0;
UPDATE `fees_records` SET `paid_status` =
  CASE WHEN `amount_paid` >= `total_fee` THEN 'Paid' WHEN `amount_paid` > 0 THEN 'Partial' ELSE 'Unpaid' END;
UPDATE `fees_records` SET `challan_number` = CONCAT('CN-LEGACY-', `id`) WHERE `challan_number` IS NULL OR TRIM(`challan_number`) = '';

ALTER TABLE `fees_records`
  DROP COLUMN `amount_due`,
  DROP KEY `challan_number`,
  DROP KEY `student_id`,
  MODIFY `student_id` INT NOT NULL,
  MODIFY `challan_number` VARCHAR(30) NOT NULL,
  MODIFY `late_fee_fine` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  MODIFY `paid_status` ENUM('Paid','Unpaid','Partial') NOT NULL DEFAULT 'Unpaid',
  ADD COLUMN `balance` DECIMAL(10,2) GENERATED ALWAYS AS (`total_fee` + `late_fee_fine` - `amount_paid`) STORED AFTER `amount_paid`,
  ADD UNIQUE KEY `uq_fees_challan` (`challan_number`),
  ADD UNIQUE KEY `uq_fees_student_semester` (`student_id`, `semester_no`),
  ADD KEY `idx_fees_payment_date` (`payment_date`),
  ADD KEY `fk_fees_collected_by` (`collected_by`),
  ADD CONSTRAINT `chk_fees_amounts` CHECK (`total_fee` >= 0 AND `late_fee_fine` >= 0 AND `amount_paid` >= 0 AND `amount_paid` <= `total_fee` + `late_fee_fine`);

-- ---------------------------------------------------------------------
-- 6) Staff: profile (staff) aur monthly payroll (staff_salaries) alag
-- ---------------------------------------------------------------------
RENAME TABLE `staff_salaries` TO `backup_staff_salaries_old`;

CREATE TABLE `staff` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `staff_id_number` VARCHAR(50) NOT NULL,
  `designation` VARCHAR(100) NOT NULL,
  `base_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_user` (`user_id`),
  UNIQUE KEY `uq_staff_number` (`staff_id_number`),
  CONSTRAINT `chk_staff_salary` CHECK (`base_salary` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Har staff user ki latest row se profile banayein
INSERT INTO `staff` (`user_id`, `staff_id_number`, `designation`, `base_salary`)
SELECT o.`user_id`, o.`staff_id_number`, COALESCE(NULLIF(TRIM(o.`designation`), ''), 'Not set'), COALESCE(o.`base_salary`, 0)
FROM `backup_staff_salaries_old` o
JOIN `users` u ON u.`id` = o.`user_id`
WHERE o.`id` = (SELECT MAX(o2.`id`) FROM `backup_staff_salaries_old` o2 WHERE o2.`user_id` = o.`user_id`);

-- Jo Staff users ka koi record hi nahi tha, un ki bhi profile (Accountant baad mein update kare)
INSERT INTO `staff` (`user_id`, `staff_id_number`, `designation`, `base_salary`)
SELECT u.`id`, CONCAT('ST-USER-', u.`id`), 'Not set', 0
FROM `users` u WHERE u.`role` = 'Staff' AND NOT EXISTS (SELECT 1 FROM `staff` s WHERE s.`user_id` = u.`id`);

CREATE TABLE `staff_salaries` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staff_id` INT NOT NULL,
  `month_year` CHAR(7) NOT NULL,
  `designation` VARCHAR(100) NOT NULL,
  `base_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `allowances` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deductions` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `net_salary` DECIMAL(10,2) GENERATED ALWAYS AS (`base_salary` + `allowances` - `deductions`) STORED,
  `status` ENUM('Paid','Pending') NOT NULL DEFAULT 'Pending',
  `paid_date` DATE DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salary_staff_month` (`staff_id`, `month_year`),
  KEY `fk_salary_created_by` (`created_by`),
  CONSTRAINT `chk_salary_amounts` CHECK (`base_salary` >= 0 AND `allowances` >= 0 AND `deductions` >= 0 AND `deductions` <= `base_salary` + `allowances`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purane "June 2026" / "june 2026" -> "2026-06". Jin rows mein month khaali tha (sirf profile) woh payroll mein nahi aati.
INSERT IGNORE INTO `staff_salaries` (`staff_id`, `month_year`, `designation`, `base_salary`, `allowances`, `status`)
SELECT s.`id`, m.`ym`, COALESCE(NULLIF(TRIM(o.`designation`), ''), 'Not set'), COALESCE(o.`base_salary`, 0), COALESCE(o.`allowances`, 0),
       COALESCE(o.`status`, 'Pending')
FROM `backup_staff_salaries_old` o
JOIN `staff` s ON s.`user_id` = o.`user_id`
JOIN (
  SELECT x.`id`,
    CASE
      WHEN TRIM(x.`month_year`) REGEXP '^[0-9]{4}-[0-9]{2}$' THEN TRIM(x.`month_year`)
      WHEN SUBSTRING_INDEX(TRIM(x.`month_year`), ' ', -1) REGEXP '^[0-9]{4}$'
       AND FIELD(LOWER(LEFT(TRIM(x.`month_year`), 3)), 'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec') > 0
      THEN CONCAT(SUBSTRING_INDEX(TRIM(x.`month_year`), ' ', -1), '-',
                  LPAD(FIELD(LOWER(LEFT(TRIM(x.`month_year`), 3)), 'jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'), 2, '0'))
      ELSE NULL
    END AS `ym`
  FROM `backup_staff_salaries_old` x
) m ON m.`id` = o.`id`
WHERE m.`ym` IS NOT NULL;

-- ---------------------------------------------------------------------
-- 7) expenses
-- ---------------------------------------------------------------------
UPDATE `expenses` SET `category` = 'Other' WHERE `category` IS NULL OR TRIM(`category`) = '';
ALTER TABLE `expenses`
  MODIFY `category` VARCHAR(100) NOT NULL DEFAULT 'Other',
  ADD COLUMN `created_by` INT DEFAULT NULL AFTER `receipt`,
  ADD KEY `idx_expenses_date` (`expense_date`),
  ADD KEY `fk_expenses_created_by` (`created_by`),
  ADD CONSTRAINT `chk_expenses_amount` CHECK (`amount` > 0);

-- ---------------------------------------------------------------------
-- 8) audit_logs (naya)
-- ---------------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `user_name` VARCHAR(100) DEFAULT NULL,
  `role` VARCHAR(20) DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `details` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `fk_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- 9) Foreign keys (tables ke darmiyan rishtay - ab orphan records nahi ban sakte)
-- ---------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `fee_structure` (`id`) ON DELETE RESTRICT;
ALTER TABLE `fees_records`
  ADD CONSTRAINT `fk_fees_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_fees_collected_by` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `staff`
  ADD CONSTRAINT `fk_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `staff_salaries`
  ADD CONSTRAINT `fk_salary_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_salary_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expenses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Migration complete. Check karein: backup_orphan_students, backup_orphan_fees_records,
-- backup_staff_salaries_old tables mein purana data mehfooz hai. Sab theek ho to baad mein delete kar sakte hain.
