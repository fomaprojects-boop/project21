<?php
// This script should be run via Cron every minute
// * * * * * php /path/to/scripts/cron_check_snoozed.php

require_once dirname(__DIR__) . '/api/db.php';

try {
    // Find snoozed conversations that are due
    $stmt = $pdo->prepare("
        SELECT id FROM conversations
        WHERE status = 'snoozed'
        AND snoozed_until <= NOW()
    ");
    $stmt->execute();
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($conversations) > 0) {
        $ids = array_column($conversations, 'id');
        $inQuery = implode(',', array_fill(0, count($ids), '?'));

        // Update to 'open'
        $update = $pdo->prepare("UPDATE conversations SET status = 'open', snoozed_until = NULL WHERE id IN ($inQuery)");
        $update->execute($ids);

        echo "Re-opened " . count($ids) . " conversations.\n";

        // Ideally, create a system notification or internal note here to alert the agent
        foreach ($ids as $id) {
             // Optional: Add internal note "Snooze ended"
             // We'd need a user_id, maybe the assignee?
             // For now, just changing status puts it back in the 'Open' list which is the main goal.
        }
    } else {
        echo "No snoozed conversations due.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
