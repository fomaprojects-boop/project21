<?php
require_once 'api/db.php';

function getTableSchema($pdo, $table) {
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        echo "--- $table ---\n";
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo $row['Field'] . " | " . $row['Type'] . "\n";
        }
        echo "\n";
    } catch (Exception $e) {
        echo "Error describing $table: " . $e->getMessage() . "\n";
    }
}

getTableSchema($pdo, 'conversations');
getTableSchema($pdo, 'users');
getTableSchema($pdo, 'contacts');
?>