-- Run once on an existing database.
ALTER TABLE `Employee` ADD COLUMN `address` TEXT NULL AFTER `phone`;
