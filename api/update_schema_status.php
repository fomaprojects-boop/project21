<?php
// api/update_schema_status.php
require_once 'db.php';

try {
    // 1. Add whatsapp_status column to users table
    $sql = "ALTER TABLE users ADD COLUMN whatsapp_status ENUM('Pending', 'Connected', 'Disconnected') DEFAULT 'Pending'";
    try {
        $pdo->exec($sql);
        echo "Column 'whatsapp_status' added to 'users' table.<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "Column 'whatsapp_status' already exists.<br>";
        } else {
            throw $e;
        }
    }

    echo "Schema update completed successfully.";

} catch (PDOException $e) {
    echo "Error updating schema: " . $e->getMessage();
}
?>
