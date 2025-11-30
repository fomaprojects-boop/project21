-- Fix YouTube Channels Table
-- Run this in your database to resolve "Unknown column channel_id"

-- 1. Ensure ID is Auto Increment (Refactor old ID to channel_id if needed)
-- We check if 'id' is not int first to avoid errors if already fixed
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'youtube_channels' AND COLUMN_NAME = 'id' AND DATA_TYPE = 'varchar');
SET @sql := IF(@exist > 0, 'ALTER TABLE youtube_channels CHANGE id channel_id varchar(255) NOT NULL, DROP PRIMARY KEY, ADD id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add channel_id column if it doesn't exist (and wasn't just renamed)
SET @exist_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'youtube_channels' AND COLUMN_NAME = 'channel_id');
SET @sql_col := IF(@exist_col = 0, 'ALTER TABLE youtube_channels ADD channel_id varchar(255) NOT NULL AFTER channel_name', 'SELECT 1');
PREPARE stmt_col FROM @sql_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- 3. Add missing token columns
ALTER TABLE youtube_channels
ADD COLUMN IF NOT EXISTS access_token TEXT,
ADD COLUMN IF NOT EXISTS refresh_token TEXT,
ADD COLUMN IF NOT EXISTS added_by_user_id INT(11) DEFAULT NULL;

-- 4. Ensure tenant_id is not unique (to allow multiple channels)
-- Drop unique index if exists
SET @exist_idx := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME = 'youtube_channels' AND INDEX_NAME = 'tenant_id' AND NON_UNIQUE = 0);
SET @sql_idx := IF(@exist_idx > 0, 'DROP INDEX tenant_id ON youtube_channels', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

-- Re-add index (non-unique) if dropped or missing
SET @exist_idx_new := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME = 'youtube_channels' AND INDEX_NAME = 'tenant_id');
SET @sql_idx_new := IF(@exist_idx_new = 0, 'CREATE INDEX tenant_id ON youtube_channels (tenant_id)', 'SELECT 1');
PREPARE stmt_idx_new FROM @sql_idx_new;
EXECUTE stmt_idx_new;
DEALLOCATE PREPARE stmt_idx_new;
