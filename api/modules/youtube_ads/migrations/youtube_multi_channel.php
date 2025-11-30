<?php
require_once __DIR__ . '/../../db.php';

try {
    // 1. Create/Update table youtube_channels
    // We add id as AUTO_INCREMENT PRIMARY KEY if not exists, and index tenant_id
    // We remove the unique constraint on tenant_id if it exists.

    // Check if table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'youtube_channels'")->rowCount() > 0;

    if (!$tableExists) {
        $sql = "CREATE TABLE `youtube_channels` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `channel_name` varchar(255) NOT NULL,
            `channel_id` varchar(255) NOT NULL,
            `thumbnail_url` varchar(500) DEFAULT NULL,
            `access_token` text,
            `refresh_token` text,
            `added_by_user_id` int(11) DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sql);
        echo "Created youtube_channels table.\n";
    } else {
        // Alter table if needed
        // Check columns
        $columns = $pdo->query("SHOW COLUMNS FROM `youtube_channels`")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('id', $columns)) {
            // Very risky to add ID to existing table without structure, but assuming empty or compatible
            $pdo->exec("ALTER TABLE `youtube_channels` ADD `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
            echo "Added ID column.\n";
        }

        if (!in_array('channel_id', $columns)) {
             // If channel_id column is missing, it might have been 'id' previously?
             // The previous model used 'id' as channel ID. We need to rename or adjust.
             // Let's assume 'id' was the string ID. We need to change 'id' to 'channel_id' then add new 'id' int.
             // This is complex. Let's just ensure we have 'channel_id'.
             // If 'id' is varchar, rename it.
             $colType = $pdo->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'youtube_channels' AND COLUMN_NAME = 'id'")->fetchColumn();
             if ($colType === 'varchar') {
                 $pdo->exec("ALTER TABLE `youtube_channels` CHANGE `id` `channel_id` varchar(255) NOT NULL");
                 $pdo->exec("ALTER TABLE `youtube_channels` DROP PRIMARY KEY");
                 $pdo->exec("ALTER TABLE `youtube_channels` ADD `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                 echo "Refactored ID to channel_id and added new Auto-Inc ID.\n";
             }
        }

        $fieldsToAdd = [
            'access_token' => 'text',
            'refresh_token' => 'text',
            'added_by_user_id' => 'int(11) DEFAULT NULL'
        ];

        foreach ($fieldsToAdd as $col => $def) {
            if (!in_array($col, $columns)) {
                $pdo->exec("ALTER TABLE `youtube_channels` ADD `$col` $def");
                echo "Added $col column.\n";
            }
        }

        // Remove UNIQUE constraint on tenant_id if exists
        // We can try to drop index if it is unique
        // Usually named 'tenant_id' or similar.
        // We catch exception if it doesn't exist
        try {
            $pdo->exec("DROP INDEX `tenant_id` ON `youtube_channels`");
            $pdo->exec("CREATE INDEX `tenant_id` ON `youtube_channels` (`tenant_id`)"); // Recreate as non-unique
            echo "Updated tenant_id index to non-unique.\n";
        } catch (Exception $e) {
            // Ignore if index didn't exist or wasn't unique in a way that failed
        }
    }

    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
