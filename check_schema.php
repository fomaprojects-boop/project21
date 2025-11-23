<?php
// check_schema.php
// Diagnostic tool to check DB tables

header('Content-Type: text/plain');
require_once 'api/db.php';

function checkTable($pdo, $table) {
    echo "--- Table: $table ---\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo $col['Field'] . " (" . $col['Type'] . ") " . ($col['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
        }
    } catch (Exception $e) {
        echo "Error describing $table: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

try {
    echo "Checking Database Schema...\n";
    checkTable($pdo, 'users');
    checkTable($pdo, 'contacts');
    checkTable($pdo, 'conversations');
    checkTable($pdo, 'messages');

    // Check strict mode
    $stmt = $pdo->query("SELECT @@sql_mode");
    $mode = $stmt->fetchColumn();
    echo "SQL Mode: $mode\n";

} catch (Exception $e) {
    echo "DB Connection Error: " . $e->getMessage();
}
?>
