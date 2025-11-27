<?php
// api/workflow_helper.php

// Helper function for logging debug messages related to workflows
if (!function_exists('log_debug')) {
    function log_debug($message) {
        $debug_log_file_v1 = __DIR__ . '/../webhook_debug.log';
        $debug_log_file_v2 = __DIR__ . '/webhook_debug.log';

        $timestamp = date('Y-m-d H:i:s');
        $msg = "[$timestamp] [WORKFLOW] $message\n";

        try { file_put_contents($debug_log_file_v1, $msg, FILE_APPEND); } catch (Throwable $e) {}
        try { file_put_contents($debug_log_file_v2, $msg, FILE_APPEND); } catch (Throwable $e) {}

        // Also log to system error log
        error_log("[ChatMe Workflow] $message");
    }
}

// Main Workflow Processing Function
// $contextData can contain: 'msg_body', 'event_type' ('message_received', 'conversation_started', 'conversation_closed')
function processWorkflows($pdo, $userId, $conversationId, $contextData = []) {
    try {
        // Normalize context data
        $msgBody = $contextData['msg_body'] ?? '';
        $eventType = $contextData['event_type'] ?? 'message_received'; // Default to generic message event

        log_debug("Processing workflows for Event: $eventType");

        // Fetch active workflows
        $stmt = $pdo->prepare("SELECT id, name, trigger_type, workflow_data FROM workflows WHERE is_active = 1");
        $stmt->execute();
        $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($workflows as $wf) {
            $triggerType = $wf['trigger_type'];
            $data = json_decode($wf['workflow_data'], true);
            $nodes = $data['nodes'] ?? [];

            $shouldTrigger = false;

            // Match Event Type with Trigger Type
            if ($triggerType === 'Conversation Started' && $eventType === 'conversation_started') {
                $shouldTrigger = true;
            }
            elseif ($triggerType === 'Message Received' && $eventType === 'message_received') {
                $shouldTrigger = true;
            }
            elseif ($triggerType === 'Conversation Closed' && $eventType === 'conversation_closed') {
                $shouldTrigger = true;
            }
            // Add other triggers here (e.g., specific keywords if needed)

            if ($shouldTrigger) {
                log_debug("Workflow Triggered: " . $wf['name']);
                executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, null); // Start from root
                // We don't break here if we want multiple independent workflows to potentially run,
                // but usually one per event is safer to avoid conflicts.
                // Let's stick to break for now to avoid loops/spam.
                break;
            }
        }
    } catch (Exception $e) {
        log_debug("Workflow Error: " . $e->getMessage());
    }
}

function executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $parentId = null) {
    // Find start node or next node
    letNextNodes:
    $nextNodes = [];
    if ($parentId === null) {
        // Find trigger (root)
        foreach ($nodes as $n) {
            if ($n['type'] === 'trigger') {
                $parentId = $n['id']; // Start from trigger
                goto letNextNodes; // Jump to find children of trigger
            }
        }
    } else {
        // Find children
        foreach ($nodes as $n) {
            if (isset($n['parentId']) && $n['parentId'] == $parentId) {
                $nextNodes[] = $n;
            }
        }
    }

    foreach ($nextNodes as $node) {
        log_debug("Executing Node ID: " . $node['id'] . " Type: " . $node['type']);

        // EXECUTE ACTION
        if ($node['type'] === 'message' || $node['type'] === 'action') {
            sendWorkflowReply($pdo, $userId, $conversationId, $node['content']);
            // Continue to next node immediately
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $node['id']);
        }
        else if ($node['type'] === 'add_tag') {
            // Content format: "Add Tag: VIP"
            $tag = str_replace('Add Tag: ', '', $node['content']);
            if (!empty($tag)) {
                addContactTag($pdo, $conversationId, $tag);
            }
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $node['id']);
        }
        else if ($node['type'] === 'update_contact') {
            // Use structured data if available, else try parsing content
            $field = $node['data']['field'] ?? null;
            $value = $node['data']['value'] ?? null;

            if ($field && $value !== null) {
                updateContactField($pdo, $conversationId, $field, $value);
            }
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $node['id']);
        }
        else if ($node['type'] === 'assign') {
            // Basic assignment logic stub
            log_debug("Assignment node reached (Logic pending)");
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $node['id']);
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
        $tags = json_decode($currentTagsJson, true) ?? [];

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

function updateContactField($pdo, $conversationId, $field, $value) {
    try {
        $allowedFields = ['email', 'name', 'notes'];
        if (!in_array($field, $allowedFields)) return;

        $stmt = $pdo->prepare("SELECT contact_id FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $contactId = $stmt->fetchColumn();

        if ($contactId) {
            $sql = "UPDATE contacts SET $field = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$value, $contactId]);
            log_debug("Updated Contact $contactId field '$field' to '$value'");
        }
    } catch (Exception $e) {
        log_debug("Error updating contact: " . $e->getMessage());
    }
}

function sendWorkflowReply($pdo, $userId, $conversationId, $content) {
    // 1. Get Settings
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return;

    $token = $user['whatsapp_access_token'];
    $phoneId = $user['whatsapp_phone_number_id'];

    // 2. Get Recipient Phone
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) return;
    $to = preg_replace('/[^0-9]/', '', $contact['phone_number']);

    // 3. Send API
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
    $res = curl_exec($ch);
    curl_close($ch);

    log_debug("Workflow Auto-Reply Sent: " . $res);

    // 4. Save to DB
    try {
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, status) VALUES (?, 'agent', ?, ?, NOW(), 'sent')");
        $stmt->execute([$conversationId, $userId, $content]);
    } catch (Exception $e) {
        log_debug("Error saving workflow reply: " . $e->getMessage());
    }
}
?>
