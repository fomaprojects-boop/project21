<?php
require_once '../db.php';

function addColumnIfNotExists($pdo, $table, $column, $definition) {
    try {
        // Check if column exists
        $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($check->rowCount() == 0) {
            // Column does not exist, add it
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "Added column `$column` to table `$table`.\n";
        } else {
            echo "Column `$column` already exists in table `$table`.\n";
        }
    } catch (PDOException $e) {
        echo "Error adding column `$column`: " . $e->getMessage() . "\n";
    }
}

echo "Starting Schema Migration for Pro Features...\n";

// 1. Messages Table Updates
addColumnIfNotExists($pdo, 'messages', 'message_type', "VARCHAR(50) DEFAULT 'text'");
addColumnIfNotExists($pdo, 'messages', 'is_internal', "TINYINT(1) DEFAULT 0");
addColumnIfNotExists($pdo, 'messages', 'scheduled_at', "DATETIME NULL");
addColumnIfNotExists($pdo, 'messages', 'interactive_data', "JSON NULL");

// 2. Conversations Table Updates
addColumnIfNotExists($pdo, 'conversations', 'snoozed_until', "DATETIME NULL");

// 3. Contacts Table Updates
addColumnIfNotExists($pdo, 'contacts', 'tags', "JSON NULL");
addColumnIfNotExists($pdo, 'contacts', 'notes', "TEXT NULL");
addColumnIfNotExists($pdo, 'contacts', 'email', "VARCHAR(255) NULL"); // Ensure email exists

echo "Migration Completed.\n";
?>
