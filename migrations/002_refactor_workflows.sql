-- 002_refactor_workflows.sql

-- Update workflows table
-- We use IGNORE or checks usually, but here we will try to Add columns if they don't exist.
-- Since MySQL doesn't support "IF NOT EXISTS" in ADD COLUMN easily in one line without procedure,
-- we will handle the "safety" in the PHP runner or just use simple ALTERs and catch errors.

-- 1. Ensure workflows table has the right columns for Rule-Based Engine
-- Note: 'workflow_data' JSON column stays for legacy reference or can be ignored.
-- We add 'trigger_type' and 'keywords'. 'is_active' was added in previous migrations.

ALTER TABLE workflows ADD COLUMN trigger_type VARCHAR(50) DEFAULT 'KEYWORD';
ALTER TABLE workflows ADD COLUMN keywords TEXT NULL;
-- (keywords stores comma-separated strings for fuzzy matching)

-- 2. Create workflow_steps table
CREATE TABLE IF NOT EXISTS workflow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workflow_id INT NOT NULL,
    step_order INT NOT NULL DEFAULT 1,
    action_type ENUM('SEND_MESSAGE', 'ASSIGN_AGENT', 'ADD_TAG', 'ASK_QUESTION', 'DELAY') NOT NULL,
    content TEXT NULL,
    meta_data JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add index for performance on fetching steps
CREATE INDEX idx_workflow_steps_workflow_id ON workflow_steps(workflow_id);
CREATE INDEX idx_workflow_steps_order ON workflow_steps(step_order);
