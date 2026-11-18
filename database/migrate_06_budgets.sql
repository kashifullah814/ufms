-- =====================================================================
-- UFMS migration 06: Budgets with approval workflow
--   Draft -> Submitted (to F&PC) -> FPC Approved -> Syndicate Approved -> Senate Approved (active)
--   Any stage can Return it (editable again). A Revised budget (copy of the active one) goes
--   through the same stages; when the Senate approves it the old one becomes Superseded.
--   budgets.active_fy is NULL unless the budget is active, and it is UNIQUE, so the database
--   itself allows only one active budget per fiscal year.
-- Run ONCE after migrate_05 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

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

-- Migration complete.
