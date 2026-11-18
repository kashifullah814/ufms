-- =====================================================================
-- UFMS migration 03: Expenses -> payment vouchers (PV)
--   * group heads get system keys (code finds "all heads under Non-Salaries")
--   * expenses.category (free text) -> expenses.account_id (expense head)
--       Utility Bills -> R&M Electricity, Stationery -> Purchasing,
--       Maintenance -> Repairing, Allowance / Other -> Other Non-Salary
--   * category column dropped (same ALTER as the NOT NULL + FK, so if any
--     row could not be mapped nothing is dropped)
-- Run ONCE after migrate_02 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

UPDATE `accounts` SET `system_key` = CASE `id`
    WHEN 1  THEN 'GRP_ASSETS'
    WHEN 4  THEN 'GRP_LIABILITIES'
    WHEN 6  THEN 'GRP_EQUITY'
    WHEN 8  THEN 'GRP_RECEIPTS'
    WHEN 11 THEN 'GRP_STUDENT_CHARGES'
    WHEN 25 THEN 'GRP_PAYMENTS'
    WHEN 26 THEN 'GRP_SALARIES'
    WHEN 31 THEN 'GRP_NON_SALARIES'
  END
WHERE `id` IN (1, 4, 6, 8, 11, 25, 26, 31) AND `is_group` = 1 AND `system_key` IS NULL;

ALTER TABLE `expenses` ADD COLUMN `account_id` INT DEFAULT NULL AFTER `title`;

UPDATE `expenses` e
SET e.`account_id` = (SELECT a.`id` FROM `accounts` a WHERE a.`system_key` = CASE e.`category`
        WHEN 'Utility Bills' THEN 'EXP_ELECTRICITY'
        WHEN 'Stationery'    THEN 'EXP_PURCHASING'
        WHEN 'Maintenance'   THEN 'EXP_REPAIRING'
        ELSE 'EXP_OTHER'
    END);

ALTER TABLE `expenses`
  MODIFY `account_id` INT NOT NULL,
  ADD KEY `idx_expenses_account` (`account_id`),
  ADD CONSTRAINT `fk_expenses_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  DROP COLUMN `category`;

-- Migration complete.
