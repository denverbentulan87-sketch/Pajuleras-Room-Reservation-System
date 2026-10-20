-- Run this ONCE only if you imported 01_schema.sql BEFORE the
-- "Erase personal details" feature was added. (New installs already have it.)
USE `pajuleras_bh`;
ALTER TABLE `tenants` ADD COLUMN `anonymized_at` DATETIME NULL AFTER `status`;
