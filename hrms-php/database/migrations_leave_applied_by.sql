-- Run once on an existing database.
ALTER TABLE `LeaveRequest` ADD COLUMN `appliedById` VARCHAR(191) NULL AFTER `appliedAt`;
