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
            // Staff members might not have WhatsApp tokens, so we fetch the tenant_id from the contact
            $workflowUserId = $_SESSION['user_id'];
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
                }
            } catch (Exception $e) {
                // Keep default session user if query fails
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