-- =====================================================================
--  Pajuleras Boarding House Management System
--  Database schema  (MySQL 5.7+ / MySQL 8 / MariaDB 10.3+)
--
--  HOW TO IMPORT
--    phpMyAdmin : open phpMyAdmin > Import > choose this file > Go
--    Command    : mysql -u root -p < 01_schema.sql
--
--  Creates the database "pajuleras_bh", all tables, the ONE admin
--  account, and the default boarding-house settings.
--  Demo records (rooms, tenants, ...) are optional: 02_sample_data.sql
--
--  Default login:  username  admin
--                  password  Pajuleras@2026   (you must change it at first login)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `pajuleras_bh`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `pajuleras_bh`;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- The one and only administrator (the landlord).
-- The PRIMARY KEY + CHECK (id = 1) means the database itself refuses a
-- second admin row.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin` (
  `id`                   TINYINT UNSIGNED NOT NULL,
  `username`             VARCHAR(50)  NOT NULL,
  `full_name`            VARCHAR(120) NOT NULL,
  `email`                VARCHAR(150) NULL,
  `password_hash`        VARCHAR(255) NOT NULL,
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at`        DATETIME     NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_single_admin` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Throttle log (failed logins, inquiry submissions, tracking look-ups)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `throttle` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`         VARCHAR(20)  NOT NULL,
  `ip`           VARCHAR(45)  NOT NULL,
  `identifier`   VARCHAR(120) NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_throttle_lookup` (`kind`, `ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Boarding-house settings shown on the public website
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(60) NOT NULL,
  `setting_value` TEXT        NULL,
  `updated_at`    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Rooms.  monthly_rate and deposit_amount are PER BED for shared rooms
-- (capacity > 1) and per room when the room has a single bed.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rooms` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_no`        VARCHAR(20)  NOT NULL,
  `room_name`      VARCHAR(80)  NULL,
  `room_type`      ENUM('shared','private') NOT NULL DEFAULT 'shared',
  `gender_policy`  ENUM('any','male','female') NOT NULL DEFAULT 'any',
  `capacity`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `monthly_rate`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deposit_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amenities`      VARCHAR(255) NULL,
  `description`    TEXT NULL,
  `photo`          VARCHAR(255) NULL,
  `status`         ENUM('active','maintenance') NOT NULL DEFAULT 'active',
  `is_public`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_room_no` (`room_no`),
  CONSTRAINT `chk_room_capacity` CHECK (`capacity` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tenants (registered customers)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_code`        VARCHAR(20)  NULL,
  `full_name`          VARCHAR(120) NOT NULL,
  `gender`             ENUM('male','female') NOT NULL,
  `birth_date`         DATE NULL,
  `contact_no`         VARCHAR(20)  NOT NULL,
  `email`              VARCHAR(150) NULL,
  `home_address`       VARCHAR(255) NULL,
  `occupation`         ENUM('student','employee','other') NOT NULL DEFAULT 'student',
  `school_or_workplace` VARCHAR(150) NULL,
  `id_presented`       VARCHAR(100) NULL,
  `guardian_name`      VARCHAR(120) NULL,
  `guardian_contact`   VARCHAR(20)  NULL,
  `notes`              TEXT NULL,
  `status`             ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `messenger`          VARCHAR(120) NULL,
  `source`             ENUM('admin','inquiry') NOT NULL DEFAULT 'admin',
  `consent_at`         DATETIME NULL,
  `anonymized_at`      DATETIME NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_code` (`tenant_code`),
  KEY `idx_tenant_name` (`full_name`),
  KEY `idx_tenant_contact` (`contact_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Customer inquiries (messages sent from the public website)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inquiries` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_code`          VARCHAR(16)  NOT NULL,
  `name`              VARCHAR(120) NOT NULL,
  `contact_no`        VARCHAR(20)  NOT NULL,
  `email`             VARCHAR(150) NULL,
  `room_id`           INT UNSIGNED NULL,
  `preferred_move_in` DATE NULL,
  `occupation`        ENUM('student','employee','other') NULL,
  `gender`            ENUM('male','female') NULL,
  `school_or_workplace` VARCHAR(150) NULL,
  `tenant_id`         INT UNSIGNED NULL,
  `auto_result`       ENUM('confirmed','full','info') NULL,
  `consent_at`        DATETIME NULL,
  `subject`           VARCHAR(150) NOT NULL,
  `status`            ENUM('new','replied','converted','closed') NOT NULL DEFAULT 'new',
  `admin_unread`      TINYINT(1) NOT NULL DEFAULT 1,
  `ip`                VARCHAR(45) NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inquiry_ref` (`ref_code`),
  KEY `idx_inquiry_status` (`status`, `admin_unread`),
  KEY `idx_inquiry_ip` (`ip`, `created_at`),
  CONSTRAINT `fk_inquiry_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inquiry_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inquiry_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `inquiry_id` INT UNSIGNED NOT NULL,
  `sender`     ENUM('customer','admin','system') NOT NULL,
  `body`       TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_inquiry` (`inquiry_id`, `created_at`),
  CONSTRAINT `fk_msg_inquiry` FOREIGN KEY (`inquiry_id`) REFERENCES `inquiries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Reservations (also the room assignment and the stay record)
--   pending    : recorded, not yet confirmed (does NOT hold a bed)
--   confirmed  : room secured (holds the bed(s))
--   checked_in : tenant has moved in (occupies the bed(s))
--   completed  : tenant has checked out
--   cancelled  : cancelled before check-in
-- monthly_rate / deposit_amount / advance_amount are the TOTALS for the
-- number of beds reserved.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservations` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reservation_no` VARCHAR(20)  NULL,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `room_id`        INT UNSIGNED NOT NULL,
  `inquiry_id`     INT UNSIGNED NULL,
  `beds`           TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `move_in_date`   DATE NOT NULL,
  `move_out_date`  DATE NULL,
  `status`         ENUM('pending','confirmed','checked_in','completed','cancelled') NOT NULL DEFAULT 'pending',
  `monthly_rate`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deposit_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `advance_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `confirmed_at`   DATETIME NULL,
  `checked_in_at`  DATETIME NULL,
  `checked_out_at` DATETIME NULL,
  `cancelled_at`   DATETIME NULL,
  `cancel_reason`  VARCHAR(255) NULL,
  `hold_until`     DATETIME NULL,
  `notice_date`    DATE NULL,
  `checkin_notes`  TEXT NULL,
  `checkout_notes` TEXT NULL,
  `deposit_kept_rent`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deposit_kept_other`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `deposit_kept_reason` VARCHAR(255) NULL,
  `notes`          TEXT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservation_no` (`reservation_no`),
  KEY `idx_res_hold` (`status`, `hold_until`),
  KEY `idx_res_room_status` (`room_id`, `status`),
  KEY `idx_res_tenant` (`tenant_id`),
  KEY `idx_res_status_movein` (`status`, `move_in_date`),
  CONSTRAINT `fk_res_tenant`  FOREIGN KEY (`tenant_id`)  REFERENCES `tenants` (`id`)   ON DELETE RESTRICT,
  CONSTRAINT `fk_res_room`    FOREIGN KEY (`room_id`)    REFERENCES `rooms` (`id`)     ON DELETE RESTRICT,
  CONSTRAINT `fk_res_inquiry` FOREIGN KEY (`inquiry_id`) REFERENCES `inquiries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_res_beds`   CHECK (`beds` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Payments (advance payments, deposits, rent, refunds)
-- Payments are never deleted; a wrong entry is VOIDED with a reason.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no`     VARCHAR(20)  NULL,
  `reservation_id` INT UNSIGNED NOT NULL,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `payment_type`   ENUM('advance','deposit','rent','other','refund') NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `payment_date`   DATE NOT NULL,
  `method`         ENUM('cash','gcash','bank_transfer','other') NOT NULL DEFAULT 'cash',
  `reference_no`   VARCHAR(80)  NULL,
  `remarks`        VARCHAR(255) NULL,
  `is_void`        TINYINT(1) NOT NULL DEFAULT 0,
  `void_reason`    VARCHAR(255) NULL,
  `voided_at`      DATETIME NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_no` (`receipt_no`),
  KEY `idx_pay_res` (`reservation_id`, `is_void`),
  KEY `idx_pay_date` (`payment_date`),
  CONSTRAINT `fk_pay_res`    FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pay_tenant` FOREIGN KEY (`tenant_id`)      REFERENCES `tenants` (`id`)      ON DELETE RESTRICT,
  CONSTRAINT `chk_pay_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ---------------------------------------------------------------------
-- Activity log (audit trail of what the admin did)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action`     VARCHAR(60)  NOT NULL,
  `entity`     VARCHAR(40)  NULL,
  `entity_id`  INT UNSIGNED NULL,
  `details`    VARCHAR(500) NULL,
  `ip`         VARCHAR(45)  NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- The single admin account (password: Pajuleras@2026, change on first login)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `admin` (`id`, `username`, `full_name`, `email`, `password_hash`, `must_change_password`)
VALUES (1, 'admin', 'Zenaida Pajuleras', NULL, '$2y$10$8rB1T/MvCB2t2pKRTH1AB.dqgYtSeP4.78xgyvPyG7F07teAJWqOu', 1);

-- ---------------------------------------------------------------------
-- Default settings (editable later in Admin > Settings)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('site_name',        'Pajuleras Boarding House'),
('tagline',          'Rooms for students, employees, and other guests in Poblacion, Inabanga, Bohol'),
('landlord_name',    'Mrs. Zenaida Pajuleras'),
('address',          'Poblacion, Inabanga, Bohol'),
('phone',            '0900 000 0000'),
('email',            ''),
('facebook',         ''),
('gcash_no',         ''),
('contact_hours',    'Daily, 8:00 AM to 8:00 PM'),
('about_text',       'Pajuleras Boarding House is a small accommodation business in Poblacion, Inabanga, Bohol. We rent rooms and bed spaces to students, employees, and other individuals who need a place to stay, whether for a few months or for the long term.'),
('advance_months',   '1'),
('requirements',     'Valid ID or school ID\nFully filled-out tenant information (name, contact number, home address)\nName and contact number of a parent, guardian, or emergency contact\nAdvance payment to secure the room'),
('payment_schedule', 'Rent is paid monthly, in advance, on the same day of the month you moved in.\nAn advance payment is collected once your reservation is confirmed.\nThe security deposit is refundable when you check out, after the room is checked.\nPayments are accepted in cash or by GCash/bank transfer. An official receipt is issued for every payment.'),
('house_rules',      'Keep the room and shared areas clean.\nQuiet hours are from 10:00 PM to 5:00 AM.\nVisitors are welcome in the common area only and must leave before quiet hours.\nTurn off lights, fans, and chargers when leaving the room.\nReport any damage or problem to the landlord right away.\nPay the monthly rent on or before the due date.\nNo smoking, drinking, or gambling inside the premises.');
