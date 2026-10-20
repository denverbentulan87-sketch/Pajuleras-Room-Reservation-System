-- Run this ONCE only if you imported 01_schema.sql BEFORE online GCash payments
-- were added. (New installs already have it.)
USE `pajuleras_bh`;

-- ---------------------------------------------------------------------
-- GCash payments sent online by tenants, waiting for the landlord to check
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_submissions` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_code` VARCHAR(12)  NOT NULL,
  `reservation_id`  INT UNSIGNED NOT NULL,
  `tenant_id`       INT UNSIGNED NOT NULL,
  `payment_type`    ENUM('advance','deposit','rent','other') NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `paid_date`       DATE NOT NULL,
  `gcash_ref`       VARCHAR(40)  NOT NULL,
  `sender_name`     VARCHAR(120) NULL,
  `proof_file`      VARCHAR(60)  NULL,
  `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reject_reason`   VARCHAR(255) NULL,
  `payment_id`      INT UNSIGNED NULL,
  `reviewed_at`     DATETIME NULL,
  `ip`              VARCHAR(45)  NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_code` (`submission_code`),
  KEY `idx_sub_status` (`status`, `created_at`),
  KEY `idx_sub_ref` (`gcash_ref`),
  CONSTRAINT `fk_sub_res`    FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sub_tenant` FOREIGN KEY (`tenant_id`)      REFERENCES `tenants` (`id`)      ON DELETE RESTRICT,
  CONSTRAINT `chk_sub_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

