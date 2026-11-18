-- =====================================================================
-- UFMS - University Finance Management System
-- FRESH INSTALL: naya, khaali database banata hai (demo accounts ke saath).
-- Purana data rakhna hai to is ke bajaye migrate_existing_db.sql chalayein.
-- Tested on MySQL 8/9 (WAMP) and MariaDB 10.11.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `university_finance_db` DEFAULT CHARACTER SET utf8mb4;
USE `university_finance_db`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `announcements`, `portal_strikes`, `login_attempts`, `budget_approvals`, `budget_lines`, `budgets`, `other_receipts`, `fee_payments`, `voucher_lines`, `vouchers`, `voucher_sequences`, `accounts`, `audit_logs`, `staff_salaries`, `staff`, `expenses`, `fees_records`, `students`, `fee_structure`, `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- users: har login account (Admin, Accountant, Student, Staff)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `employee_id` VARCHAR(50) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('Admin','Accountant','Student','Staff') NOT NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `can_manage_fees` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_salaries` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_expenses` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage_accounts` TINYINT(1) NOT NULL DEFAULT 0,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `failed_logins` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `lock_reason` VARCHAR(30) DEFAULT NULL,
  `reset_otp` VARCHAR(255) DEFAULT NULL,
  `otp_expiry` DATETIME DEFAULT NULL,
  `otp_attempts` INT NOT NULL DEFAULT 0,
  `accepted_policy_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- fee_structure: har program ki fee (Admin set karta hai)
-- ---------------------------------------------------------------------
CREATE TABLE `fee_structure` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `program_name` VARCHAR(150) NOT NULL,
  `tuition_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `admission_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `exam_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `other_charges` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fee_structure_program` (`program_name`),
  CONSTRAINT `chk_fee_structure_positive` CHECK (`tuition_fee` >= 0 AND `admission_fee` >= 0 AND `exam_fee` >= 0 AND `other_charges` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- students: student profile (login users table mein)
-- ---------------------------------------------------------------------
CREATE TABLE `students` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `reg_number` VARCHAR(50) NOT NULL,
  `program_id` INT DEFAULT NULL,
  `semester` TINYINT NOT NULL DEFAULT 1,
  `scholarship_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_reg` (`reg_number`),
  UNIQUE KEY `uq_students_user` (`user_id`),
  KEY `idx_students_program` (`program_id`),
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `fee_structure` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_students_semester` CHECK (`semester` BETWEEN 1 AND 8),
  CONSTRAINT `chk_students_discount` CHECK (`scholarship_discount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- fees_records: har challan (ek student ka ek semester = ek challan)
-- balance khud calculate hota hai: total + fine - paid
-- ---------------------------------------------------------------------
CREATE TABLE `fees_records` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `challan_number` VARCHAR(30) NOT NULL,
  `semester_no` TINYINT DEFAULT NULL,
  `total_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tuition_amt` DECIMAL(10,2) DEFAULT NULL,
  `admission_amt` DECIMAL(10,2) DEFAULT NULL,
  `exam_amt` DECIMAL(10,2) DEFAULT NULL,
  `other_amt` DECIMAL(10,2) DEFAULT NULL,
  `late_fee_fine` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `balance` DECIMAL(10,2) GENERATED ALWAYS AS (`total_fee` + `late_fee_fine` - `amount_paid`) STORED,
  `paid_status` ENUM('Paid','Unpaid','Partial') NOT NULL DEFAULT 'Unpaid',
  `payment_date` DATE DEFAULT NULL,
  `collected_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fees_challan` (`challan_number`),
  UNIQUE KEY `uq_fees_student_semester` (`student_id`, `semester_no`),
  KEY `idx_fees_payment_date` (`payment_date`),
  KEY `fk_fees_collected_by` (`collected_by`),
  CONSTRAINT `fk_fees_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_fees_collected_by` FOREIGN KEY (`collected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_fees_amounts` CHECK (`total_fee` >= 0 AND `late_fee_fine` >= 0 AND `amount_paid` >= 0 AND `amount_paid` <= `total_fee` + `late_fee_fine`),
  CONSTRAINT `chk_fees_split` CHECK (
    (`tuition_amt` IS NULL AND `admission_amt` IS NULL AND `exam_amt` IS NULL AND `other_amt` IS NULL)
    OR (`tuition_amt` IS NOT NULL AND `admission_amt` IS NOT NULL AND `exam_amt` IS NOT NULL AND `other_amt` IS NOT NULL
        AND `tuition_amt` >= 0 AND `admission_amt` >= 0 AND `exam_amt` >= 0 AND `other_amt` >= 0
        AND `tuition_amt` + `admission_amt` + `exam_amt` + `other_amt` = `total_fee`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- staff: staff profile (ek staff = ek row)
-- Regular/Contract = monthly (base_salary); Visiting = per hour, Daily Wages = per day (pay_rate)
-- ---------------------------------------------------------------------
CREATE TABLE `staff` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `staff_id_number` VARCHAR(50) NOT NULL,
  `designation` VARCHAR(100) NOT NULL,
  `employment_type` ENUM('Regular','Contract','Visiting','Daily Wages') NOT NULL DEFAULT 'Regular',
  `pay_basis` ENUM('Monthly','Hourly','Daily')
      GENERATED ALWAYS AS (CASE `employment_type` WHEN 'Visiting' THEN 'Hourly' WHEN 'Daily Wages' THEN 'Daily' ELSE 'Monthly' END) STORED,
  `base_salary` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `pay_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_user` (`user_id`),
  UNIQUE KEY `uq_staff_number` (`staff_id_number`),
  CONSTRAINT `fk_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_staff_salary` CHECK (`base_salary` >= 0),
  CONSTRAINT `chk_staff_pay_rate` CHECK (`pay_rate` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- staff_salaries: monthly payroll (ek staff, ek mahina = ek row)
-- month_year format: 'YYYY-MM'
-- ---------------------------------------------------------------------
CREATE TABLE `staff_salaries` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `staff_id` INT NOT NULL,
  `month_year` CHAR(7) NOT NULL,
  `designation` VARCHAR(100) NOT NULL,
  `employment_type` ENUM('Regular','Contract','Visiting','Daily Wages') NOT NULL DEFAULT 'Regular',
  `pay_basis` ENUM('Monthly','Hourly','Daily')
      GENERATED ALWAYS AS (CASE `employment_type` WHEN 'Visiting' THEN 'Hourly' WHEN 'Daily Wages' THEN 'Daily' ELSE 'Monthly' END) STORED,
  `units` DECIMAL(7,2) DEFAULT NULL,
  `rate` DECIMAL(10,2) DEFAULT NULL,
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
  KEY `idx_salary_type_month` (`employment_type`, `month_year`),
  KEY `fk_salary_created_by` (`created_by`),
  CONSTRAINT `fk_salary_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_salary_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_salary_amounts` CHECK (`base_salary` >= 0 AND `allowances` >= 0 AND `deductions` >= 0 AND `deductions` <= `base_salary` + `allowances`),
  CONSTRAINT `chk_salary_units` CHECK (
    (`employment_type` IN ('Regular','Contract') AND `units` IS NULL AND `rate` IS NULL)
    OR (`employment_type` IN ('Visiting','Daily Wages') AND `units` IS NOT NULL AND `rate` IS NOT NULL
        AND `units` > 0 AND `rate` > 0 AND `base_salary` = ROUND(`units` * `rate`, 2)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- audit_logs: kis ne kab kya kiya (login, add, delete ...)
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
  KEY `fk_audit_user` (`user_id`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- login_attempts: failed logins per IP (login throttling). blocked_until is
-- set on the failure that blocked the IP; rows older than 1 day are pruned.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- portal_strikes: wrong-portal logins (correct password, wrong role).
-- 3 in 24 hours block the account for 24 hours (users.lock_reason = 'WRONG_PORTAL').
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- announcements: Reminders (UI mein "Reminders").
--   type 'General' = notice (audience All = landing page + har dashboard; Students / Staff / Accountants = us group ka dashboard)
--   type 'Fee'     = fee reminder sirf un students ko jin ki dues baqi hain: fee_scope 'All' | 'Filter' (program / semester) | 'Student'
--   start_date (bhejne ki tareekh) se end_date ("show until", NULL = hamesha) tak; pinned pehle. due_date fee reminder ke liye.
--   email_requested / recipients_count / emails_sent / emails_failed = delivery ka record (history table).
-- ---------------------------------------------------------------------
CREATE TABLE `announcements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `type` ENUM('General','Fee') NOT NULL DEFAULT 'General',
  `title` VARCHAR(150) NOT NULL,
  `body` TEXT NOT NULL,
  `audience` ENUM('All','Students','Staff','Accountants') NOT NULL DEFAULT 'All',
  `fee_scope` ENUM('All','Filter','Student') DEFAULT NULL,
  `target_program_id` INT DEFAULT NULL,
  `target_semester` TINYINT DEFAULT NULL,
  `target_student_id` INT DEFAULT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `email_requested` TINYINT(1) NOT NULL DEFAULT 0,
  `recipients_count` INT DEFAULT NULL,
  `emails_sent` INT NOT NULL DEFAULT 0,
  `emails_failed` INT NOT NULL DEFAULT 0,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_announcements_active` (`audience`, `start_date`, `end_date`),
  KEY `idx_announcements_type` (`type`, `start_date`, `end_date`),
  KEY `fk_announcements_user` (`created_by`),
  KEY `fk_announcements_program` (`target_program_id`),
  KEY `fk_announcements_student` (`target_student_id`),
  CONSTRAINT `fk_announcements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_announcements_program` FOREIGN KEY (`target_program_id`) REFERENCES `fee_structure` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_announcements_student` FOREIGN KEY (`target_student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_announcements_dates` CHECK (`end_date` IS NULL OR `end_date` >= `start_date`),
  CONSTRAINT `chk_announcements_type` CHECK (
        (`type` = 'General' AND `fee_scope` IS NULL)
     OR (`type` = 'Fee' AND `fee_scope` IS NOT NULL AND `due_date` IS NOT NULL AND `audience` = 'Students'
         AND (`fee_scope` <> 'Student' OR `target_student_id` IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- reminder_recipients: har reminder ka har recipient (bhejte waqt ka snapshot). Sending sirf manual hai (koi cron nahi).
--   status 'None' = sirf dashboard | 'Sent' = email chali gayi | 'Failed' = email nakam (error; "Retry failed" se dobara)
--   sent_at = email ka waqt; seen_at = user ne portal par pehli dafa reminder dekha.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- accounts: chart of accounts (heads). Group heads sirf totals ke liye hain.
-- ---------------------------------------------------------------------
CREATE TABLE `accounts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `type` ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
  `parent_id` INT DEFAULT NULL,
  `is_group` TINYINT(1) NOT NULL DEFAULT 0,
  `is_cash_bank` TINYINT(1) NOT NULL DEFAULT 0,
  `system_key` VARCHAR(40) DEFAULT NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_code` (`code`),
  UNIQUE KEY `uq_accounts_system_key` (`system_key`),
  KEY `idx_accounts_parent` (`parent_id`),
  CONSTRAINT `fk_accounts_parent` FOREIGN KEY (`parent_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_accounts_cash_bank` CHECK (`is_cash_bank` = 0 OR (`type` = 'Asset' AND `is_group` = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- voucher_sequences: har voucher type + fiscal year ka last number
-- ---------------------------------------------------------------------
CREATE TABLE `voucher_sequences` (
  `voucher_type` ENUM('RV','PV','JV') NOT NULL,
  `fiscal_year` SMALLINT NOT NULL,
  `last_no` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`voucher_type`, `fiscal_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- vouchers: RV (receipt), PV (payment), JV (journal). Kabhi delete nahi hote.
-- fiscal_year = July-June saal ka start year (2026-07-01 .. 2027-06-30 => 2026)
-- ---------------------------------------------------------------------
CREATE TABLE `vouchers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `voucher_no` VARCHAR(20) NOT NULL,
  `voucher_type` ENUM('RV','PV','JV') NOT NULL,
  `voucher_date` DATE NOT NULL,
  `fiscal_year` SMALLINT GENERATED ALWAYS AS (IF(MONTH(`voucher_date`) >= 7, YEAR(`voucher_date`), YEAR(`voucher_date`) - 1)) STORED,
  `narration` VARCHAR(255) NOT NULL,
  `total_amount` DECIMAL(14,2) NOT NULL,
  `source_type` ENUM('fee_payment','salary','expense','receipt','manual') NOT NULL DEFAULT 'manual',
  `source_id` INT DEFAULT NULL,
  `status` ENUM('Posted','Cancelled') NOT NULL DEFAULT 'Posted',
  `cancelled_by` INT DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  `cancel_reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vouchers_no` (`voucher_no`),
  KEY `idx_vouchers_date` (`voucher_date`),
  KEY `idx_vouchers_fy_type` (`fiscal_year`, `voucher_type`),
  KEY `idx_vouchers_source` (`source_type`, `source_id`),
  KEY `fk_vouchers_created_by` (`created_by`),
  KEY `fk_vouchers_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_vouchers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vouchers_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_vouchers_total` CHECK (`total_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- voucher_lines: har line ya debit hai ya credit (dono nahi).
-- Total debit = total credit PHP ka post_voucher() check karta hai.
-- ---------------------------------------------------------------------
CREATE TABLE `voucher_lines` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `voucher_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_narration` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lines_voucher` (`voucher_id`),
  KEY `idx_lines_account` (`account_id`),
  CONSTRAINT `fk_lines_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_lines_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_lines_amounts` CHECK ((`debit` > 0 AND `credit` = 0) OR (`credit` > 0 AND `debit` = 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- ---------------------------------------------------------------------
-- expenses: university ke kharche (har expense ek expense head par; PV banta hai)
-- ---------------------------------------------------------------------
CREATE TABLE `expenses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `account_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `expense_date` DATE NOT NULL,
  `description` TEXT,
  `receipt` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `idx_expenses_account` (`account_id`),
  KEY `fk_expenses_created_by` (`created_by`),
  CONSTRAINT `fk_expenses_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_expenses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_expenses_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- other_receipts: HEC/HED grants aur student related charges (har ek ka RV)
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- budgets / budget_lines / budget_approvals: budget per head per fiscal year,
-- approval F&PC -> Syndicate -> Senate. active_fy UNIQUE = ek FY mein ek active budget.
-- ---------------------------------------------------------------------
CREATE TABLE `budgets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fiscal_year` SMALLINT NOT NULL,
  `budget_type` ENUM('Original','Revised') NOT NULL DEFAULT 'Original',
  `revision_of` INT DEFAULT NULL,
  `title` VARCHAR(150) NOT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('Draft','Submitted','FPC Approved','Syndicate Approved','Senate Approved','Returned','Superseded') NOT NULL DEFAULT 'Draft',
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `active_fy` SMALLINT GENERATED ALWAYS AS (IF(`is_active` = 1, `fiscal_year`, NULL)) STORED,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budgets_active_fy` (`active_fy`),
  KEY `idx_budgets_fy_status` (`fiscal_year`, `status`),
  KEY `fk_budgets_revision_of` (`revision_of`),
  KEY `fk_budgets_created_by` (`created_by`),
  CONSTRAINT `fk_budgets_revision_of` FOREIGN KEY (`revision_of`) REFERENCES `budgets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_budgets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_budgets_active` CHECK (`is_active` = 0 OR `status` = 'Senate Approved'),
  CONSTRAINT `chk_budgets_revision` CHECK ((`budget_type` = 'Original' AND `revision_of` IS NULL) OR (`budget_type` = 'Revised' AND `revision_of` IS NOT NULL)),
  CONSTRAINT `chk_budgets_fy` CHECK (`fiscal_year` BETWEEN 2000 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Har head ki budget raqam (sirf Income/Expense sub-heads)
CREATE TABLE `budget_lines` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `budget_id` INT NOT NULL,
  `account_id` INT NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budget_lines_head` (`budget_id`, `account_id`),
  KEY `idx_budget_lines_account` (`account_id`),
  CONSTRAINT `fk_budget_lines_budget` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budget_lines_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_budget_lines_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Har stage ka record (meeting date, reference, minutes PDF). Kabhi delete nahi hota.
CREATE TABLE `budget_approvals` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `budget_id` INT NOT NULL,
  `stage` ENUM('F&PC','Syndicate','Senate') NOT NULL,
  `action` ENUM('Submitted','Approved','Returned') NOT NULL,
  `meeting_date` DATE DEFAULT NULL,
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `minutes_file` VARCHAR(255) DEFAULT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `recorded_by` INT DEFAULT NULL,
  `recorded_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_budget_approvals_budget` (`budget_id`),
  KEY `fk_budget_approvals_recorded_by` (`recorded_by`),
  CONSTRAINT `fk_budget_approvals_budget` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_budget_approvals_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_budget_approvals_meeting` CHECK (`action` = 'Submitted' OR `meeting_date` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- DEMO DATA (testing/viva ke liye). Real use se pehle passwords badal dein.
-- Demo fees/salaries/expenses ke vouchers: import ke baad chalayein
--   /opt/lampp/bin/php database/backfill_vouchers.php --run
--   Admin      : admin@ustb.edu        / Admin@1234   (pehle login par naya password maangega)
--   Accountant : accountant@ustb.edu   / Account@1234
--   Student    : student@ustb.edu      / Student@1234
--   Staff      : staff@ustb.edu        / Staff@1234
-- =====================================================================
INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `status`, `can_manage_fees`, `can_manage_salaries`, `can_manage_expenses`, `can_manage_accounts`, `must_change_password`) VALUES
(1, 'Main Admin',        'admin@ustb.edu',      '$2y$10$EM/zy6IZHNns3D0epJWBK.h9dskPiq78gxJVPMgygPo0BXjtFc9My', 'Admin',      'Active', 1, 1, 1, 1, 1),
(2, 'Demo Accountant',   'accountant@ustb.edu', '$2y$10$rpMeGJ/w/6Qc/Z43AfjOUetohXc/.StjwnHrDYKGWWnhyaMuhbb0C', 'Accountant', 'Active', 1, 1, 1, 1, 0),
(3, 'Demo Student',      'student@ustb.edu',    '$2y$10$PKOY.brkryiwzfqtagCE2.qBnnp0.LQ.vn0gmHacyaQr5Q0cyXQ3.', 'Student',    'Active', 0, 0, 0, 0, 0),
(4, 'Demo Staff Member', 'staff@ustb.edu',      '$2y$10$W26bdQmcsMVv04nyRVrc/uus/uwQzTS1tkCWep/2zxDynOjc0IreS', 'Staff',      'Active', 0, 0, 0, 0, 0);

INSERT INTO `fee_structure` (`id`, `program_name`, `tuition_fee`, `admission_fee`, `exam_fee`, `other_charges`) VALUES
(1, 'Software Engineering', 34000.00, 10000.00, 4000.00, 12000.00),
(2, 'Computer Science',     30000.00,  7000.00, 4000.00,  3000.00);

INSERT INTO `students` (`id`, `user_id`, `reg_number`, `program_id`, `semester`, `scholarship_discount`) VALUES
(1, 3, '2024-USTB-000001', 1, 2, 0.00);

INSERT INTO `fees_records` (`student_id`, `challan_number`, `semester_no`, `total_fee`, `late_fee_fine`, `amount_paid`, `paid_status`, `payment_date`, `collected_by`) VALUES
(1, 'CN-DEMO-0001', 1, 60000.00, 0.00, 60000.00, 'Paid',    CURDATE() - INTERVAL 40 DAY, 2),
(1, 'CN-DEMO-0002', 2, 50000.00, 0.00, 30000.00, 'Partial', CURDATE() - INTERVAL 5 DAY,  2);

INSERT INTO `staff` (`id`, `user_id`, `staff_id_number`, `designation`, `base_salary`) VALUES
(1, 4, 'ST-0001', 'Lecturer', 60000.00);

INSERT INTO `staff_salaries` (`staff_id`, `month_year`, `designation`, `base_salary`, `allowances`, `deductions`, `status`, `paid_date`, `created_by`) VALUES
(1, DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m'), 'Lecturer', 60000.00, 5000.00, 2000.00, 'Paid', CURDATE() - INTERVAL 20 DAY, 2),
(1, DATE_FORMAT(CURDATE(), '%Y-%m'),                    'Lecturer', 60000.00, 5000.00, 2000.00, 'Pending', NULL, 2);


-- ---------------------------------------------------------------------
-- Seed: chart of accounts (supervisor ke receipt/payment heads)
-- ---------------------------------------------------------------------
INSERT INTO `accounts` (`id`, `code`, `name`, `type`, `parent_id`, `is_group`, `is_cash_bank`, `system_key`) VALUES
( 1, '1000', 'Assets',                         'Asset',     NULL, 1, 0, 'GRP_ASSETS'),
( 2, '1100', 'Bank - Main Account',            'Asset',     1,    0, 1, 'BANK'),
( 3, '1110', 'Cash in Hand',                   'Asset',     1,    0, 1, 'CASH'),
( 4, '2000', 'Liabilities',                    'Liability', NULL, 1, 0, 'GRP_LIABILITIES'),
( 5, '2100', 'Salary Deductions Payable',      'Liability', 4,    0, 0, 'SAL_DEDUCTIONS'),
( 6, '3000', 'Equity',                         'Equity',    NULL, 1, 0, 'GRP_EQUITY'),
( 7, '3100', 'Opening Fund Balance',           'Equity',    6,    0, 0, 'OPENING_FUND'),
( 8, '4000', 'Receipts',                       'Income',    NULL, 1, 0, 'GRP_RECEIPTS'),
( 9, '4100', 'HEC Grant',                      'Income',    8,    0, 0, 'GRANT_HEC'),
(10, '4200', 'HED Grant',                      'Income',    8,    0, 0, 'GRANT_HED'),
(11, '4300', 'Student Related Charges',        'Income',    8,    1, 0, 'GRP_STUDENT_CHARGES'),
(12, '4301', 'Tuition Fee',                    'Income',    11,   0, 0, 'FEE_TUITION'),
(13, '4302', 'Other Charges',                  'Income',    11,   0, 0, 'FEE_OTHER'),
(14, '4303', 'Hostel Charges',                 'Income',    11,   0, 0, 'FEE_HOSTEL'),
(15, '4304', 'Transport Charges',              'Income',    11,   0, 0, 'FEE_TRANSPORT'),
(16, '4305', 'Repeat Paper Fee',               'Income',    11,   0, 0, 'FEE_REPEAT'),
(17, '4306', 'Summer Semester Fee',            'Income',    11,   0, 0, 'FEE_SUMMER'),
(18, '4307', 'DMC / Transcript Fee',           'Income',    11,   0, 0, 'FEE_DMC'),
(19, '4308', 'Degree Fee',                     'Income',    11,   0, 0, 'FEE_DEGREE'),
(20, '4309', 'Degree Verification Fee',        'Income',    11,   0, 0, 'FEE_VERIFICATION'),
(21, '4310', 'Migration Fee',                  'Income',    11,   0, 0, 'FEE_MIGRATION'),
(22, '4311', 'Admission Fee',                  'Income',    11,   0, 0, 'FEE_ADMISSION'),
(23, '4312', 'Exam Fee',                       'Income',    11,   0, 0, 'FEE_EXAM'),
(24, '4313', 'Late Fee Fine',                  'Income',    11,   0, 0, 'FEE_FINE'),
(25, '5000', 'Payments',                       'Expense',   NULL, 1, 0, 'GRP_PAYMENTS'),
(26, '5100', 'Salaries - Established',         'Expense',   25,   1, 0, 'GRP_SALARIES'),
(27, '5101', 'Salaries - Regular (Permanent)', 'Expense',   26,   0, 0, 'SAL_REGULAR'),
(28, '5102', 'Salaries - Contract',            'Expense',   26,   0, 0, 'SAL_CONTRACT'),
(29, '5103', 'Salaries - Visiting',            'Expense',   26,   0, 0, 'SAL_VISITING'),
(30, '5104', 'Salaries - Daily Wages',         'Expense',   26,   0, 0, 'SAL_DAILY'),
(31, '5200', 'Non-Salaries',                   'Expense',   25,   1, 0, 'GRP_NON_SALARIES'),
(32, '5201', 'POL / Transport',                'Expense',   31,   0, 0, 'EXP_POL'),
(33, '5202', 'R&M Electricity',                'Expense',   31,   0, 0, 'EXP_ELECTRICITY'),
(34, '5203', 'Purchasing',                     'Expense',   31,   0, 0, 'EXP_PURCHASING'),
(35, '5204', 'Repairing',                      'Expense',   31,   0, 0, 'EXP_REPAIRING'),
(36, '5299', 'Other Non-Salary',               'Expense',   31,   0, 0, 'EXP_OTHER');

-- ---------------------------------------------------------------------
-- Demo opening balance (Admin baad mein manual JV se theek kar sakta hai)
-- 2026-06-01 => FY 2025-26 => JV-2526-0001
-- ---------------------------------------------------------------------
INSERT INTO `voucher_sequences` (`voucher_type`, `fiscal_year`, `last_no`) VALUES ('JV', 2025, 1);

INSERT INTO `vouchers` (`id`, `voucher_no`, `voucher_type`, `voucher_date`, `narration`, `total_amount`, `source_type`, `created_by`)
SELECT 1, 'JV-2526-0001', 'JV', '2026-06-01', 'Opening balance of Bank - Main Account (demo figure)', 10000000.00, 'manual',
       (SELECT `id` FROM `users` WHERE `role` = 'Admin' ORDER BY `id` LIMIT 1);

INSERT INTO `voucher_lines` (`voucher_id`, `account_id`, `debit`, `credit`, `line_narration`) VALUES
(1, 2, 10000000.00, 0.00, 'Opening bank balance'),
(1, 7, 0.00, 10000000.00, 'Opening fund balance');

-- Demo expense (head 5202 R&M Electricity)
INSERT INTO `expenses` (`title`, `account_id`, `amount`, `expense_date`, `description`, `created_by`) VALUES
('Electricity bill (main campus)', 33, 30000.00, CURDATE() - INTERVAL 10 DAY, 'Monthly WAPDA bill', 2);
