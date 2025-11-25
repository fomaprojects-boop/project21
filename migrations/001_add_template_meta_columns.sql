-- Migration to support Meta WhatsApp message templates integration

-- Add columns to settings table for API credentials
ALTER TABLE `settings`
ADD COLUMN `whatsapp_access_token` TEXT DEFAULT NULL,
ADD COLUMN `whatsapp_business_account_id` VARCHAR(255) DEFAULT NULL;

-- Add columns to message_templates table for tracking Meta's IDs
ALTER TABLE `message_templates`
ADD COLUMN `meta_template_id` VARCHAR(255) DEFAULT NULL,
ADD COLUMN `meta_template_name` VARCHAR(255) DEFAULT NULL;

-- Populate settings with placeholder data to avoid "not configured" errors on first run
-- The user should replace these values with their actual credentials in the settings page.
UPDATE `settings` SET `whatsapp_business_account_id` = 'placeholder_waba_id', `whatsapp_access_token` = 'placeholder_access_token' WHERE id = 1;
