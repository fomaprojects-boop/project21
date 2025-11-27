<?php
require_once 'api/db.php';

try {
    echo "Starting schema update for message_templates...\n";

    // 1. Add `header_type` column
    try {
        $pdo->exec("ALTER TABLE message_templates ADD COLUMN header_type VARCHAR(50) DEFAULT NULL");
        echo "Added 'header_type' column.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            echo "Error adding 'header_type': " . $e->getMessage() . "\n";
        } else {
            echo "'header_type' column already exists.\n";
        }
    }

    // 2. Add `buttons_data` column (JSON)
    try {
        $pdo->exec("ALTER TABLE message_templates ADD COLUMN buttons_data TEXT DEFAULT NULL");
        echo "Added 'buttons_data' column.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') === false) {
            echo "Error adding 'buttons_data': " . $e->getMessage() . "\n";
        } else {
            echo "'buttons_data' column already exists.\n";
        }
    }

    echo "Schema update complete.\n";

} catch (Exception $e) {
    echo "Critical Error: " . $e->getMessage() . "\n";
}
?>
