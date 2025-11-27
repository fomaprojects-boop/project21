<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$conversation_id = $data['conversation_id'] ?? null;
$status = $data['status'] ?? null; // 'open' or 'closed'

if (!$conversation_id || !in_array($status, ['open', 'closed'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

try {
    // Include workflow helper
    if (file_exists(__DIR__ . '/workflow_helper.php')) {
        require_once __DIR__ . '/workflow_helper.php';
    }

    if ($status === 'closed') {
        $stmt = $pdo->prepare("UPDATE conversations SET status = ?, closed_by = ?, closed_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $_SESSION['user_id'], $conversation_id]);

        // Trigger 'Conversation Closed' Workflow
        if (function_exists('processWorkflows')) {
            // Determine the correct User ID (Tenant Owner) for credentials
            // Staff members might not have WhatsApp tokens.
            $workflowUserId = $_SESSION['user_id'];

            try {
                $tenantFound = false;

                // Strategy 1: Check if contacts table has tenant_id
                try {
                    $stmt_tenant = $pdo->prepare("
                        SELECT c.tenant_id
                        FROM contacts c
                        JOIN conversations conv ON c.id = conv.contact_id
                        WHERE conv.id = ?
                        LIMIT 1
                    ");
                    $stmt_tenant->execute([$conversation_id]);
                    $tenantId = $stmt_tenant->fetchColumn();

                    if ($tenantId) {
                        $workflowUserId = $tenantId;
                        $tenantFound = true;
                    }
                } catch (Exception $e) {
                    // Column might not exist, continue to next strategy
                }

                // Strategy 2: Check the messages table for the channel owner (user_id of inbound messages)
                // Inbound messages (contact) are tagged with the user_id of the channel owner in webhook.php
                if (!$tenantFound) {
                    $stmt_owner = $pdo->prepare("SELECT user_id FROM messages WHERE conversation_id = ? AND sender_type = 'contact' AND user_id IS NOT NULL LIMIT 1");
                    $stmt_owner->execute([$conversation_id]);
                    $ownerId = $stmt_owner->fetchColumn();
                    if ($ownerId) {
                        $workflowUserId = $ownerId;
                    }
                }

            } catch (Exception $e) {
                // Keep default session user if query fails
            }

            // Debug Log
            if (function_exists('log_debug')) {
                 log_debug("Resolved Workflow User ID: $workflowUserId for Conv ID: $conversation_id. Status: closed. Invoking processWorkflows...");
            }

            processWorkflows($pdo, $workflowUserId, $conversation_id, [
                'event_type' => 'conversation_closed'
            ]);
        }

    } else {
        $stmt = $pdo->prepare("UPDATE conversations SET status = ?, closed_by = NULL, closed_at = NULL WHERE id = ?");
        $stmt->execute([$status, $conversation_id]);
    }

    echo json_encode(['success' => true, 'message' => 'Status updated', 'new_status' => $status]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>