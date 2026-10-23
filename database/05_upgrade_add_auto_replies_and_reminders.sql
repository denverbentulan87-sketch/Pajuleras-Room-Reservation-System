-- Run this ONCE only if you imported 01_schema.sql BEFORE automatic inquiry
-- replies, tenant auto-registration and payment reminders were added.
-- (New installs already have all of this.)
USE `pajuleras_bh`;

ALTER TABLE `tenants`
  ADD COLUMN `messenger` VARCHAR(120) NULL AFTER `email`,
  ADD COLUMN `source` ENUM('admin','inquiry') NOT NULL DEFAULT 'admin' AFTER `status`,
  ADD COLUMN `consent_at` DATETIME NULL AFTER `source`;

ALTER TABLE `inquiries`
  ADD COLUMN `gender` ENUM('male','female') NULL AFTER `occupation`,
  ADD COLUMN `school_or_workplace` VARCHAR(150) NULL AFTER `gender`,
  ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `school_or_workplace`,
  ADD COLUMN `auto_result` ENUM('confirmed','full','info') NULL AFTER `tenant_id`,
  ADD COLUMN `consent_at` DATETIME NULL AFTER `auto_result`,
  ADD CONSTRAINT `fk_inquiry_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL;

ALTER TABLE `inquiry_messages` MODIFY `sender` ENUM('customer','admin','system') NOT NULL;

-- ---------------------------------------------------------------------
-- Messages the system sends (or prepares) for tenants: rent reminders,
-- GCash decisions, inquiry replies. Kept as a log so nothing is unclear.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      INT UNSIGNED NULL,
  `reservation_id` INT UNSIGNED NULL,
  `inquiry_id`     INT UNSIGNED NULL,
  `type`           VARCHAR(30)  NOT NULL,
  `channel`        ENUM('email','sms','messenger','manual') NOT NULL,
  `recipient`      VARCHAR(190) NULL,
  `subject`        VARCHAR(190) NULL,
  `body`           TEXT NOT NULL,
  `status`         ENUM('sent','failed','manual','skipped') NOT NULL,
  `error`          VARCHAR(255) NULL,
  `dedupe_key`     VARCHAR(120) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`        DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notif_dedupe` (`dedupe_key`, `status`),
  KEY `idx_notif_status` (`status`, `created_at`),
  KEY `idx_notif_tenant` (`tenant_id`),
  CONSTRAINT `fk_notif_tenant` FOREIGN KEY (`tenant_id`)      REFERENCES `tenants` (`id`)      ON DELETE SET NULL,
  CONSTRAINT `fk_notif_res`    FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

