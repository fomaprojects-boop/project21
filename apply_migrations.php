<?php
require_once 'api/db.php';

$migrationsDir = __DIR__ . '/migrations';
$files = scandir($migrationsDir);

foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
        $sql = file_get_contents($migrationsDir . '/' . $file);
        try {
            $pdo->exec($sql);
            echo "Migration applied: $file\n";
        } catch (PDOException $e) {
            echo "Error applying migration $file: " . $e->getMessage() . "\n";
        }
    }
}
?>
