<?php
// api/fix_db_schema.php

// Display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    global $pdo;
    
    echo "<h2>Database Schema Fixer</h2>";
    echo "Checking `ad_reports` table...<br>";
    
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM ad_reports LIKE 'analytics_data'");
    if ($stmt->rowCount() == 0) {
        echo "Column 'analytics_data' missing. Adding it...<br>";
        $pdo->exec("ALTER TABLE ad_reports ADD COLUMN analytics_data LONGTEXT DEFAULT NULL AFTER report_date");
        echo "<strong style='color:green'>SUCCESS: Column 'analytics_data' added successfully.</strong><br>";
    } else {
        echo "<strong style='color:blue'>INFO: Column 'analytics_data' already exists. No action needed.</strong><br>";
    }
    
    echo "<br>Done. You can now run the scheduler debug again.";
    
} catch (PDOException $e) {
    echo "<strong style='color:red'>Error: " . $e->getMessage() . "</strong>";
}
?>