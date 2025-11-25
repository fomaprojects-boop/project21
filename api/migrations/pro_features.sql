-- Add columns for Pro Features

-- Messages Table
ALTER TABLE `messages` ADD COLUMN `message_type` VARCHAR(50) DEFAULT 'text';
ALTER TABLE `messages` ADD COLUMN `is_internal` TINYINT(1) DEFAULT 0;
ALTER TABLE `messages` ADD COLUMN `scheduled_at` DATETIME NULL;
ALTER TABLE `messages` ADD COLUMN `interactive_data` JSON NULL;

-- Conversations Table
ALTER TABLE `conversations` ADD COLUMN `snoozed_until` DATETIME NULL;

-- Contacts Table
ALTER TABLE `contacts` ADD COLUMN `tags` JSON NULL;
ALTER TABLE `contacts` ADD COLUMN `notes` TEXT NULL;
ALTER TABLE `contacts` ADD COLUMN `email` VARCHAR(255) NULL;
