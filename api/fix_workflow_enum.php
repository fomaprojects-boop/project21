<?php
// api/fix_workflow_enum.php
require_once 'db.php';

try {
    echo "Updating 'workflow_steps' table schema...\n";

    // Modify the ENUM column to include new action types
    // Note: We must include ALL existing values + the NEW ones.
    $sql = "ALTER TABLE workflow_steps
            MODIFY COLUMN action_type
            ENUM('SEND_MESSAGE', 'ASSIGN_AGENT', 'ADD_TAG', 'ASK_QUESTION', 'DELAY', 'UPDATE_CONTACT', 'CREATE_JOB_ORDER')
            NOT NULL";

    $pdo->exec($sql);

    echo "SUCCESS: 'action_type' column updated to support CREATE_JOB_ORDER and UPDATE_CONTACT.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
