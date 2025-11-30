<?php
// api/migration_fix_snooze.php

require_once 'db.php';

header('Content-Type: text/plain');

try {
    echo "Starting database migration...\n";
    echo "Target: Adding 'snoozed' status to 'conversations' table.\n\n";

    // 1. Inspect current column (Optional, for logging)
    $stmt = $pdo->query("SHOW COLUMNS FROM conversations WHERE Field = 'status'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Current Type: " . $column['Type'] . "\n";

    // 2. Execute ALTER TABLE
    // We explicitly define the ENUM to include 'snoozed'.
    // Note: This operation preserves existing data ('open', 'closed').
    $sql = "ALTER TABLE conversations MODIFY COLUMN status ENUM('open', 'closed', 'snoozed') DEFAULT 'open'";
    $pdo->exec($sql);

    echo "Executing: $sql\n";
    echo "...\n";

    // 3. Verify
    $stmt = $pdo->query("SHOW COLUMNS FROM conversations WHERE Field = 'status'");
    $newColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "New Type: " . $newColumn['Type'] . "\n\n";

    if (strpos($newColumn['Type'], "'snoozed'") !== false) {
        echo "SUCCESS: Migration completed successfully.\n";
    } else {
        echo "WARNING: 'snoozed' not found in new column definition. Check database permissions.\n";
    }

} catch (PDOException $e) {
    echo "ERROR: Database operation failed.\n";
    echo "Message: " . $e->getMessage() . "\n";
}
?>
