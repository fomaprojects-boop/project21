<?php
require_once 'api/db.php';

try {
    $tables = ['messages', 'workflows'];
    foreach ($tables as $table) {
        echo "Table: $table\n";
        try {
            $stmt = $pdo->query("DESCRIBE $table");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $col) {
                echo "  " . $col['Field'] . " (" . $col['Type'] . ")\n";
            }
        } catch (Exception $e) {
            echo "  Table not found or error: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
