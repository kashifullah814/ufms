-- =====================================================================
-- UFMS migration 04: Salaries - Established (employment types) + salary vouchers
--   staff:          employment_type, pay_basis (generated from the type), pay_rate
--   staff_salaries: employment_type + pay_basis copied onto each bill, units (hours/days), rate
--   Regular / Contract -> Monthly (base_salary); Visiting -> Hourly; Daily Wages -> Daily
--   (hours or days x rate = base_salary of the bill).
-- Existing staff and bills become Regular / Monthly (column default).
-- Also tightens chk_fees_split from migrate_02 (see bottom).
-- Run ONCE after migrate_03 (take a mysqldump backup first). MariaDB 10.4.
-- =====================================================================

USE `university_finance_db`;

ALTER TABLE `staff`
  ADD COLUMN `employment_type` ENUM('Regular','Contract','Visiting','Daily Wages') NOT NULL DEFAULT 'Regular' AFTER `designation`,
  ADD COLUMN `pay_basis` ENUM('Monthly','Hourly','Daily')
      GENERATED ALWAYS AS (CASE `employment_type` WHEN 'Visiting' THEN 'Hourly' WHEN 'Daily Wages' THEN 'Daily' ELSE 'Monthly' END) STORED AFTER `employment_type`,
  ADD COLUMN `pay_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `base_salary`,
  ADD CONSTRAINT `chk_staff_pay_rate` CHECK (`pay_rate` >= 0);

ALTER TABLE `staff_salaries`
  ADD COLUMN `employment_type` ENUM('Regular','Contract','Visiting','Daily Wages') NOT NULL DEFAULT 'Regular' AFTER `designation`,
  ADD COLUMN `pay_basis` ENUM('Monthly','Hourly','Daily')
      GENERATED ALWAYS AS (CASE `employment_type` WHEN 'Visiting' THEN 'Hourly' WHEN 'Daily Wages' THEN 'Daily' ELSE 'Monthly' END) STORED AFTER `employment_type`,
  ADD COLUMN `units` DECIMAL(7,2) DEFAULT NULL AFTER `pay_basis`,
  ADD COLUMN `rate` DECIMAL(10,2) DEFAULT NULL AFTER `units`,
  ADD KEY `idx_salary_type_month` (`employment_type`, `month_year`),
  ADD CONSTRAINT `chk_salary_units` CHECK (
    (`employment_type` IN ('Regular','Contract') AND `units` IS NULL AND `rate` IS NULL)
    OR (`employment_type` IN ('Visiting','Daily Wages') AND `units` IS NOT NULL AND `rate` IS NOT NULL
        AND `units` > 0 AND `rate` > 0 AND `base_salary` = ROUND(`units` * `rate`, 2)));

-- ---------------------------------------------------------------------
-- Fix for migrate_02: a CHECK only fails on FALSE, so a partly-NULL split
-- (e.g. tuition set, exam NULL) passed. Explicit IS NOT NULL tests close that gap.
-- ---------------------------------------------------------------------
ALTER TABLE `fees_records` DROP CONSTRAINT `chk_fees_split`;
ALTER TABLE `fees_records` ADD CONSTRAINT `chk_fees_split` CHECK (
    (`tuition_amt` IS NULL AND `admission_amt` IS NULL AND `exam_amt` IS NULL AND `other_amt` IS NULL)
    OR (`tuition_amt` IS NOT NULL AND `admission_amt` IS NOT NULL AND `exam_amt` IS NOT NULL AND `other_amt` IS NOT NULL
        AND `tuition_amt` >= 0 AND `admission_amt` >= 0 AND `exam_amt` >= 0 AND `other_amt` >= 0
        AND `tuition_amt` + `admission_amt` + `exam_amt` + `other_amt` = `total_fee`));

-- Migration complete.
