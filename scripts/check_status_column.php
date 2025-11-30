<?php
require_once 'api/db.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM conversations WHERE Field = 'status'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Column Type: " . $column['Type'] . "\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>