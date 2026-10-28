-- Run this ONCE only if you imported the database BEFORE the reservation hold,
-- move-out notice and deposit settlement features were added.
-- (New installs already have all of this.)
USE `pajuleras_bh`;

ALTER TABLE `reservations`
  ADD COLUMN `hold_until` DATETIME NULL AFTER `cancel_reason`,
  ADD COLUMN `notice_date` DATE NULL AFTER `hold_until`,
  ADD COLUMN `checkin_notes` TEXT NULL AFTER `notice_date`,
  ADD COLUMN `checkout_notes` TEXT NULL AFTER `checkin_notes`,
  ADD COLUMN `deposit_kept_rent` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `checkout_notes`,
  ADD COLUMN `deposit_kept_other` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `deposit_kept_rent`,
  ADD COLUMN `deposit_kept_reason` VARCHAR(255) NULL AFTER `deposit_kept_other`,
  ADD KEY `idx_res_hold` (`status`, `hold_until`);
