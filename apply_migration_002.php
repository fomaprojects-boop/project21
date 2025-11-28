<?php
require_once __DIR__ . '/api/db.php';

echo "Applying migration 002_refactor_workflows.sql...\n";

try {
    // 1. Alter workflows table
    try {
        $pdo->exec("ALTER TABLE workflows ADD COLUMN trigger_type VARCHAR(50) DEFAULT 'KEYWORD'");
        echo "Column 'trigger_type' added.\n";
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1060) {
            echo "Column 'trigger_type' already exists.\n";
        } else {
            echo "Error adding 'trigger_type': " . $e->getMessage() . "\n";
        }
    }

    try {
        $pdo->exec("ALTER TABLE workflows ADD COLUMN keywords TEXT NULL");
        echo "Column 'keywords' added.\n";
    } catch (PDOException $e) {
        if ($e->errorInfo[1] == 1060) {
            echo "Column 'keywords' already exists.\n";
        } else {
            echo "Error adding 'keywords': " . $e->getMessage() . "\n";
        }
    }

    // 2. Create workflow_steps table
    $sql = "CREATE TABLE IF NOT EXISTS workflow_steps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workflow_id INT NOT NULL,
        step_order INT NOT NULL DEFAULT 1,
        action_type ENUM('SEND_MESSAGE', 'ASSIGN_AGENT', 'ADD_TAG', 'ASK_QUESTION') NOT NULL,
        content TEXT NULL,
        meta_data JSON NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $pdo->exec($sql);
    echo "Table 'workflow_steps' created or already exists.\n";

    // 3. Add Indexes
    try {
        $pdo->exec("CREATE INDEX idx_workflow_steps_workflow_id ON workflow_steps(workflow_id)");
        echo "Index 'idx_workflow_steps_workflow_id' created.\n";
    } catch (PDOException $e) {
        // Index might exist
        echo "Index 'idx_workflow_steps_workflow_id' might already exist: " . $e->getMessage() . "\n";
    }

    try {
        $pdo->exec("CREATE INDEX idx_workflow_steps_order ON workflow_steps(step_order)");
        echo "Index 'idx_workflow_steps_order' created.\n";
    } catch (PDOException $e) {
        echo "Index 'idx_workflow_steps_order' might already exist: " . $e->getMessage() . "\n";
    }

    echo "Migration completed.\n";

} catch (Exception $e) {
    echo "Critical Error: " . $e->getMessage() . "\n";
}
?>
