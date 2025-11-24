<?php
require_once 'api/db.php';
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM messages");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns in messages table: " . implode(", ", $columns) . "\n";

    if (in_array('is_read', $columns)) {
        echo "is_read exists.\n";
    } else {
        echo "is_read MISSING.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
