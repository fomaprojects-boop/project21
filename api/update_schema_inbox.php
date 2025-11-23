<?php
require_once 'db.php';

try {
    // 1. Add 'status' to conversations if not exists
    $stmt = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'status'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE conversations ADD COLUMN status ENUM('open', 'closed') DEFAULT 'open'");
        echo "Added 'status' column to conversations.<br>";
    }

    // 2. Add 'assigned_to' to conversations if not exists
    $stmt = $pdo->query("SHOW COLUMNS FROM conversations LIKE 'assigned_to'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE conversations ADD COLUMN assigned_to INT NULL");
        echo "Added 'assigned_to' column to conversations.<br>";
    }

    // 3. Ensure 'contacts' has normalized 'phone_number' (already exists likely, but good to check)

    echo "Schema update complete.";

} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage();
}
?>