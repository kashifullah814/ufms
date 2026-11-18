-- =====================================================================
-- UFMS migration 01: Accounting engine (double-entry)
--   accounts (chart of accounts), vouchers, voucher_lines, voucher_sequences,
--   users.can_manage_accounts, seeded chart, demo opening balance JV.
-- Run ONCE on an existing database (take a mysqldump backup first).
-- Tested on MariaDB 10.4 (XAMPP 8.0.30).
-- =====================================================================

USE `university_finance_db`;

-- ---------------------------------------------------------------------
-- New permission: Accounts (vouchers, trial balance, grants, budgets)
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `can_manage_accounts` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_manage_expenses`;

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
-- Seed: chart of accounts (supervisor ke receipt/payment heads)
-- ---------------------------------------------------------------------
INSERT INTO `accounts` (`id`, `code`, `name`, `type`, `parent_id`, `is_group`, `is_cash_bank`, `system_key`) VALUES
( 1, '1000', 'Assets',                         'Asset',     NULL, 1, 0, NULL),
( 2, '1100', 'Bank - Main Account',            'Asset',     1,    0, 1, 'BANK'),
( 3, '1110', 'Cash in Hand',                   'Asset',     1,    0, 1, 'CASH'),
( 4, '2000', 'Liabilities',                    'Liability', NULL, 1, 0, NULL),
( 5, '2100', 'Salary Deductions Payable',      'Liability', 4,    0, 0, 'SAL_DEDUCTIONS'),
( 6, '3000', 'Equity',                         'Equity',    NULL, 1, 0, NULL),
( 7, '3100', 'Opening Fund Balance',           'Equity',    6,    0, 0, 'OPENING_FUND'),
( 8, '4000', 'Receipts',                       'Income',    NULL, 1, 0, NULL),
( 9, '4100', 'HEC Grant',                      'Income',    8,    0, 0, 'GRANT_HEC'),
(10, '4200', 'HED Grant',                      'Income',    8,    0, 0, 'GRANT_HED'),
(11, '4300', 'Student Related Charges',        'Income',    8,    1, 0, NULL),
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
(25, '5000', 'Payments',                       'Expense',   NULL, 1, 0, NULL),
(26, '5100', 'Salaries - Established',         'Expense',   25,   1, 0, NULL),
(27, '5101', 'Salaries - Regular (Permanent)', 'Expense',   26,   0, 0, 'SAL_REGULAR'),
(28, '5102', 'Salaries - Contract',            'Expense',   26,   0, 0, 'SAL_CONTRACT'),
(29, '5103', 'Salaries - Visiting',            'Expense',   26,   0, 0, 'SAL_VISITING'),
(30, '5104', 'Salaries - Daily Wages',         'Expense',   26,   0, 0, 'SAL_DAILY'),
(31, '5200', 'Non-Salaries',                   'Expense',   25,   1, 0, NULL),
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

-- Migration complete.
