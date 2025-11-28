<?php
// scripts/cron_process_workflows.php
// Run this every minute via Cron

// Determine Project Root (This script is in /scripts/, so root is ../)
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/api/db.php';
require_once $projectRoot . '/api/workflow_helper.php';

// Logging
$logFile = $projectRoot . '/workflow_cron.log';
function log_cron($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

log_cron("Starting Workflow Processor...");

try {
    // Find workflows that are 'DELAYED' and ready to resume
    $sql = "SELECT id, conversation_id, workflow_state FROM conversations
            WHERE workflow_state IS NOT NULL
            AND workflow_state LIKE '%\"status\":\"DELAYED\"%'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($conversations as $conv) {
        $state = json_decode($conv['workflow_state'], true);

        if ($state && isset($state['resume_at'])) {
            $resumeTime = strtotime($state['resume_at']);
            $now = time();

            if ($now >= $resumeTime) {
                log_cron("Resuming Conversation ID: {$conv['conversation_id']} (Workflow {$state['workflow_id']}, Step {$state['step_order']})");

                // Get User ID for the conversation (needed for sending messages)
                // We try to find the assigned agent or the tenant owner
                $userId = null;

                // 1. Try Assigned Agent
                $stmtUser = $pdo->prepare("SELECT assigned_to FROM conversations WHERE id = ?");
                $stmtUser->execute([$conv['conversation_id']]);
                $assignedTo = $stmtUser->fetchColumn();
                if ($assignedTo) $userId = $assignedTo;

                // 2. Fallback to Tenant Owner (via Contact -> User or Message)
                if (!$userId) {
                    $stmtOwner = $pdo->prepare("SELECT tenant_id FROM contacts WHERE id = (SELECT contact_id FROM conversations WHERE id = ?)");
                    $stmtOwner->execute([$conv['conversation_id']]);
                    $userId = $stmtOwner->fetchColumn();
                }

                // 3. Fallback to first admin
                if (!$userId) {
                    $stmtAdmin = $pdo->prepare("SELECT id FROM users WHERE role = 'Admin' LIMIT 1");
                    $stmtAdmin->execute();
                    $userId = $stmtAdmin->fetchColumn();
                }

                if ($userId) {
                    // Clear the delay state before executing to prevent loop if execution fails mid-way (optional safety)
                    // But executeWorkflowSteps starts by fetching >= step_order.
                    // If we clear state, we lose context? No, we pass IDs.
                    // Best to clear state only if successful?
                    // executeWorkflowSteps clears state at the END if finished.
                    // But here we are resuming. We should probably clear the "DELAYED" state so it doesn't run again immediately?
                    // Actually, executeWorkflowSteps might set a NEW state (another delay).
                    // So we just call it.

                    executeWorkflowSteps($pdo, $state['workflow_id'], $userId, $conv['conversation_id'], $state['step_order']);
                    $count++;
                } else {
                    log_cron("Skipping Conv {$conv['conversation_id']}: No valid User ID found to execute actions.");
                }
            }
        }
    }

    log_cron("Processed $count resumed workflows.");

} catch (Exception $e) {
    log_cron("Error: " . $e->getMessage());
}
?>
