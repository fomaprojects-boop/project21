<?php
// api/workflow_helper.php

// Helper function for logging debug messages related to workflows
if (!function_exists('log_debug')) {
    function log_debug($message) {
        $debug_log_file_v1 = __DIR__ . '/../webhook_debug.log';
        $debug_log_file_v2 = __DIR__ . '/webhook_debug.log';

        $timestamp = date('Y-m-d H:i:s');
        $msg = "[$timestamp] [WORKFLOW-ENGINE] $message\n";

        try { file_put_contents($debug_log_file_v1, $msg, FILE_APPEND); } catch (Throwable $e) {}
        try { file_put_contents($debug_log_file_v2, $msg, FILE_APPEND); } catch (Throwable $e) {}

        error_log("[ChatMe Workflow] $message");
    }
}

// Main Workflow Processing Function
// $contextData can contain: 'msg_body', 'event_type' ('message_received', 'conversation_started', 'conversation_closed')
function processWorkflows($pdo, $userId, $conversationId, $contextData = []) {
    try {
        $msgBody = trim($contextData['msg_body'] ?? '');
        $eventType = $contextData['event_type'] ?? 'message_received';

        log_debug("Processing workflows. Event: $eventType, Body: " . substr($msgBody, 0, 50));

        // --- FETCH ACTIVE WORKFLOWS ---
        $stmt = $pdo->prepare("SELECT id, name, trigger_type, keywords FROM workflows WHERE is_active = 1");
        $stmt->execute();
        $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        log_debug("Found " . count($workflows) . " active workflows.");

        foreach ($workflows as $wf) {
            $triggerType = strtoupper($wf['trigger_type']); // KEYWORD or CONVERSATION_STARTED
            $shouldTrigger = false;

            // 1. Check Trigger Logic
            if ($triggerType === 'KEYWORD' && $eventType === 'message_received' && !empty($msgBody)) {
                $keywords = array_map('trim', explode(',', $wf['keywords']));
                foreach ($keywords as $keyword) {
                    if (empty($keyword)) continue;
                    // Case-insensitive, partial match
                    if (stripos($msgBody, $keyword) !== false) {
                        $shouldTrigger = true;
                        log_debug("MATCH! Keyword '$keyword' found in message.");
                        break;
                    }
                }
            }
            elseif ($triggerType === 'CONVERSATION_STARTED' && $eventType === 'conversation_started') {
                $shouldTrigger = true;
                log_debug("MATCH! Conversation Started event.");
            }

            if ($shouldTrigger) {
                log_debug("Executing Workflow ID: {$wf['id']} Name: {$wf['name']}");
                executeWorkflowSteps($pdo, $wf['id'], $userId, $conversationId);
                // Break after first trigger to prevent multiple workflows firing for same message?
                // For now, break is safer to avoid loops.
                break;
            }
        }

    } catch (Exception $e) {
        log_debug("Workflow Error: " . $e->getMessage());
    }
}

function executeWorkflowSteps($pdo, $workflowId, $userId, $conversationId) {
    try {
        // Fetch steps ordered by step_order
        $stmt = $pdo->prepare("SELECT action_type, content, meta_data FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC");
        $stmt->execute([$workflowId]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($steps)) {
            log_debug("No steps found for Workflow ID $workflowId");
            return;
        }

        foreach ($steps as $step) {
            $action = $step['action_type'];
            log_debug("Running Step: $action");

            if ($action === 'SEND_MESSAGE') {
                if (!empty($step['content'])) {
                    sendWorkflowReply($pdo, $userId, $conversationId, $step['content']);
                }
            }
            elseif ($action === 'ASSIGN_AGENT') {
                // Logic: Round Robin or Fewest Conversations
                // For now, we will just simulate assignment or use Round Robin if content specifies
                // Or fallback to auto-assigner helper if exists.
                // Simplified: If 'Round Robin', assign to random agent for now.
                assignAgentLogic($pdo, $conversationId, $step['content']);
            }
            elseif ($action === 'ADD_TAG') {
                if (!empty($step['content'])) {
                    addContactTag($pdo, $conversationId, $step['content']);
                }
            }
            elseif ($action === 'ASK_QUESTION') {
                // Future Implementation: Wait for reply
                log_debug("ASK_QUESTION step skipped (Not fully implemented yet).");
            }
        }

    } catch (Exception $e) {
        log_debug("Step Execution Error: " . $e->getMessage());
    }
}

// --- ACTION IMPLEMENTATIONS ---

function sendWorkflowReply($pdo, $userId, $conversationId, $content) {
    // 1. Get Settings & Token
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['whatsapp_access_token'] || !$user['whatsapp_phone_number_id']) {
        log_debug("Cannot send reply: Missing WhatsApp credentials for User $userId");
        return;
    }

    $token = $user['whatsapp_access_token'];
    $phoneId = $user['whatsapp_phone_number_id'];

    // 2. Get Recipient Phone
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) return;

    $to = preg_replace('/[^0-9]/', '', $contact['phone_number']);

    // 3. Send API Request
    $url = "https://graph.facebook.com/v21.0/$phoneId/messages";
    $data = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $content]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_debug("Sent Reply. Code: $httpCode. Resp: " . substr($response, 0, 100));

    // 4. Save to Database
    if ($httpCode == 200) {
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, status) VALUES (?, 'agent', ?, ?, NOW(), 'sent')");
            $stmt->execute([$conversationId, $userId, $content]);
        } catch (Exception $e) {
            log_debug("DB Save Failed: " . $e->getMessage());
        }
    }
}

function addContactTag($pdo, $conversationId, $tag) {
    try {
        $stmt = $pdo->prepare("SELECT contact_id FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $contactId = $stmt->fetchColumn();
        if (!$contactId) return;

        // Fetch current tags
        $stmt = $pdo->prepare("SELECT tags FROM contacts WHERE id = ?");
        $stmt->execute([$contactId]);
        $currentTagsJson = $stmt->fetchColumn();
        $tags = $currentTagsJson ? json_decode($currentTagsJson, true) : [];
        if (!is_array($tags)) $tags = [];

        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            $stmt = $pdo->prepare("UPDATE contacts SET tags = ? WHERE id = ?");
            $stmt->execute([json_encode($tags), $contactId]);
            log_debug("Added Tag '$tag' to Contact $contactId");
        }
    } catch (Exception $e) {
        log_debug("Error adding tag: " . $e->getMessage());
    }
}

function assignAgentLogic($pdo, $conversationId, $logicType) {
    try {
        $assignedId = null;

        // Fetch eligible agents (Admin or Staff)
        // Note: Ideally filter by 'is_online' or similar if available
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('Admin', 'Staff')");
        $stmt->execute();
        $agents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($agents)) return;

        if ($logicType === 'Round Robin') {
            // Simple random assignment for now (True Round Robin requires storing last assigned index)
            $assignedId = $agents[array_rand($agents)];
        }
        elseif ($logicType === 'Fewest Conversations') {
            // Query for agent with least open conversations
            // SELECT assigned_to, COUNT(*) as count FROM conversations WHERE status='open' GROUP BY assigned_to
            // Then pick lowest.
            // Simplified:
            $assignedId = $agents[0]; // Placeholder
        } else {
            $assignedId = $agents[0];
        }

        if ($assignedId) {
            $stmt = $pdo->prepare("UPDATE conversations SET assigned_to = ? WHERE id = ?");
            $stmt->execute([$assignedId, $conversationId]);
            log_debug("Assigned Conversation $conversationId to Agent $assignedId ($logicType)");
        }

    } catch (Exception $e) {
        log_debug("Assign Agent Error: " . $e->getMessage());
    }
}
?>
