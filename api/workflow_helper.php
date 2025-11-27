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

        // --- STEP 1: CHECK FOR ACTIVE WORKFLOW STATE (Waiting for Reply) ---
        // If we are waiting for a user reply to a Question node, we must resume that workflow instead of starting a new one.
        if ($eventType === 'message_received') {
            $state = getWorkflowState($pdo, $conversationId);
            if ($state) {
                log_debug("Found Active Workflow State: " . json_encode($state));
                // We have an active workflow waiting for input.
                // Check if the input matches any branch from the active node.

                // Fetch the workflow definition
                $stmt = $pdo->prepare("SELECT workflow_data FROM workflows WHERE id = ?");
                $stmt->execute([$state['workflow_id']]);
                $wfDataJson = $stmt->fetchColumn();

                if ($wfDataJson) {
                    $wfData = json_decode($wfDataJson, true);
                    $nodes = $wfData['nodes'] ?? [];
                    $edges = $wfData['edges'] ?? [];

                    // Attempt to resume
                    resumeWorkflow($pdo, $userId, $conversationId, $nodes, $edges, $state['active_node_id'], $msgBody, $state['workflow_id']);
                    return; // Stop processing other triggers
                }
            }
        }

        // --- STEP 2: CHECK FOR NEW TRIGGERS ---

        // Fetch active workflows
        // IMPORTANT: We must filter by tenant if possible, or at least be aware of it.
        // Currently get_workflows.php fetches ALL workflows.
        // For robustness, we select all active workflows and match triggers.
        $stmt = $pdo->prepare("SELECT id, name, trigger_type, workflow_data FROM workflows WHERE is_active = 1");
        $stmt->execute();
        $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        log_debug("Found " . count($workflows) . " active workflows in DB.");

        foreach ($workflows as $wf) {
            $triggerType = trim($wf['trigger_type']);
            $data = json_decode($wf['workflow_data'], true);
            $nodes = $data['nodes'] ?? [];
            $edges = $data['edges'] ?? [];

            $shouldTrigger = false;

            log_debug("Checking Workflow ID: {$wf['id']} Name: {$wf['name']} Trigger: '$triggerType' vs Event: '$eventType'");

            // Match Event Type with Trigger Type (Case Insensitive to be safe)
            if (strcasecmp($triggerType, 'Conversation Started') === 0 && $eventType === 'conversation_started') {
                $shouldTrigger = true;
            }
            elseif (strcasecmp($triggerType, 'Message Received') === 0 && $eventType === 'message_received') {
                $shouldTrigger = true;
            }
            elseif (strcasecmp($triggerType, 'Conversation Closed') === 0 && $eventType === 'conversation_closed') {
                $shouldTrigger = true;
            }
            // Add other triggers here (e.g., specific keywords if needed)

            if ($shouldTrigger) {
                log_debug("MATCH! Workflow Triggered: " . $wf['name']);

                // Clear any previous state before starting new
                clearWorkflowState($pdo, $conversationId);

                executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges, null, $wf['id']); // Start from root
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

// --- STATE MANAGEMENT HELPERS ---

function getWorkflowState($pdo, $conversationId) {
    try {
        $stmt = $pdo->prepare("SELECT workflow_state FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $json = $stmt->fetchColumn();
        return $json ? json_decode($json, true) : null;
    } catch (Exception $e) {
        return null;
    }
}

function saveWorkflowState($pdo, $conversationId, $workflowId, $nodeId) {
    try {
        $state = ['workflow_id' => $workflowId, 'active_node_id' => $nodeId, 'updated_at' => date('Y-m-d H:i:s')];
        $stmt = $pdo->prepare("UPDATE conversations SET workflow_state = ? WHERE id = ?");
        $stmt->execute([json_encode($state), $conversationId]);
        log_debug("Saved Workflow State: Conv $conversationId at Node $nodeId");
    } catch (Exception $e) {
        log_debug("Error saving state: " . $e->getMessage());
    }
}

function clearWorkflowState($pdo, $conversationId) {
    try {
        $stmt = $pdo->prepare("UPDATE conversations SET workflow_state = NULL WHERE id = ?");
        $stmt->execute([$conversationId]);
        log_debug("Cleared Workflow State for Conv $conversationId");
    } catch (Exception $e) {}
}

// --- EXECUTION LOGIC ---

function resumeWorkflow($pdo, $userId, $conversationId, $nodes, $edges, $currentNodeId, $userReply, $workflowId = null) {
    log_debug("Resuming Workflow at Node $currentNodeId with Reply: $userReply");

    // Find valid connections from the current node using EDGES or PARENT_ID
    $matchedNextNodeId = null;
    $defaultNextNodeId = null;

    // 1. Try EDGES (Graph Structure)
    if (!empty($edges)) {
        foreach ($edges as $edge) {
            // Support both React Flow formats: string IDs or objects
            $source = $edge['source'] ?? '';
            $target = $edge['target'] ?? '';

            if ($source == $currentNodeId) {
                // Check for conditions (Branching)
                // Example: Edge label or Edge data handle
                $condition = $edge['label'] ?? ($edge['data']['label'] ?? '');
                // Also check 'sourceHandle' if using handles for conditions
                $handle = $edge['sourceHandle'] ?? ''; // e.g., 'true', 'false', 'yes', 'no'

                // Normalize reply and conditions
                $cleanReply = trim(strtolower($userReply));
                $cleanCondition = trim(strtolower($condition));
                $cleanHandle = trim(strtolower($handle));

                // Match Logic: Check label OR handle
                if (($cleanCondition !== '' && $cleanCondition === $cleanReply) ||
                    ($cleanHandle !== '' && $cleanHandle === $cleanReply)) {
                    $matchedNextNodeId = $target;
                    break;
                }

                // Keep track of a default path (empty condition)
                if ($cleanCondition === '' && $cleanHandle === '') {
                    $defaultNextNodeId = $target;
                }
            }
        }
    }

    // 2. Try NODES ParentID (Tree Structure - Fallback)
    if (!$matchedNextNodeId) {
        log_debug("Edges failed. Trying ParentID fallback. Current Node: $currentNodeId, Reply: $userReply");
        foreach ($nodes as $childNode) {
            if (isset($childNode['parentId']) && $childNode['parentId'] == $currentNodeId) {
                // Check branch in root or data
                $branch = $childNode['branch'] ?? ($childNode['data']['branch'] ?? '');
                
                $cleanBranch = trim(strtolower($branch));
                $cleanReply = trim(strtolower($userReply));

                log_debug("Checking Child Node " . $childNode['id'] . " Branch: '$cleanBranch' vs Reply: '$cleanReply'");

                // Match Logic: Exact or Fuzzy
                // 1. Exact Match
                if ($cleanBranch !== '' && $cleanBranch === $cleanReply) {
                    $matchedNextNodeId = $childNode['id'];
                    log_debug("Exact match found!");
                    break;
                }
                
                // 2. Fuzzy Match (Contains) - e.g. "Yes please" contains "yes"
                if ($cleanBranch !== '' && (strpos($cleanReply, $cleanBranch) !== false || strpos($cleanBranch, $cleanReply) !== false)) {
                     $matchedNextNodeId = $childNode['id'];
                     log_debug("Fuzzy match found!");
                     break;
                }

                // Keep track of a default path (empty branch or matches nothing else)
                if ($cleanBranch === '') {
                    $defaultNextNodeId = $childNode['id'];
                }
            }
        }
    }

    // Determine where to go
    $nextNodeId = $matchedNextNodeId ?? $defaultNextNodeId;

    if ($nextNodeId) {
        // Clear state since we are moving on
        clearWorkflowState($pdo, $conversationId);
        // Continue execution from the found child
        executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges, null, $workflowId, $nextNodeId);
    } else {
        log_debug("No matching branch found for reply '$userReply'. Workflow stops or waits.");
    }
}

function executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges = [], $parentId = null, $workflowId = null, $forceNextNodeId = null) {
    // FORCE NEXT NODE: If provided (e.g. from resumeWorkflow), we skip parent searching
    if ($forceNextNodeId) {
        // Find the specific node object
        foreach ($nodes as $n) {
            if ($n['id'] == $forceNextNodeId) {
                $nextNodes = [$n];
                goto processNodes;
            }
        }
        return; // Node not found
    }

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
        // Find children using EDGES (Primary) or PARENT_ID (Legacy/Fallback)
        $foundViaEdges = false;

        if (!empty($edges)) {
            foreach ($edges as $edge) {
                if (($edge['source'] ?? '') == $parentId) {
                    $targetId = $edge['target'] ?? null;
                    if ($targetId) {
                        // Find the node object for this target ID
                        foreach ($nodes as $n) {
                            if ($n['id'] == $targetId) {
                                $nextNodes[] = $n;
                                $foundViaEdges = true;
                            }
                        }
                    }
                }
            }
        }

        // Legacy Fallback
        if (!$foundViaEdges) {
            log_debug("Searching for children of Node $parentId using ParentID strategy...");
            foreach ($nodes as $n) {
                // Loose comparison == handles string/int mismatch
                if (isset($n['parentId']) && $n['parentId'] == $parentId) {
                    $nextNodes[] = $n;
                }
            }
        }
    }

    log_debug("Found " . count($nextNodes) . " next nodes for Parent $parentId");

    processNodes:
    foreach ($nextNodes as $node) {
        log_debug("Executing Node ID: " . $node['id'] . " Type: " . $node['type']);

        // EXECUTE ACTION
        if ($node['type'] === 'message' || $node['type'] === 'action') {
            sendWorkflowReply($pdo, $userId, $conversationId, $node['content']);
            // Continue to next node immediately
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges, $node['id'], $workflowId);
        }
        else if ($node['type'] === 'question') {
             // 1. Send Interactive Message (Buttons)
             sendWorkflowQuestion($pdo, $userId, $conversationId, $node);
             // 2. SAVE STATE: "Waiting for reply to Node X"
             if ($workflowId) {
                 saveWorkflowState($pdo, $conversationId, $workflowId, $node['id']);
             }
             // 3. STOP RECURSION
             log_debug("Workflow Paused at Question Node: " . $node['id']);
             return;
        }
        else if ($node['type'] === 'add_tag') {
            // Content format: "Add Tag: VIP"
            $tag = str_replace('Add Tag: ', '', $node['content']);
            if (!empty($tag)) {
                addContactTag($pdo, $conversationId, $tag);
            }
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges, $node['id'], $workflowId);
        }
        else if ($node['type'] === 'update_contact') {
            // Use structured data if available, else try parsing content
            $field = $node['data']['field'] ?? null;
            $value = $node['data']['value'] ?? null;

            if ($field && $value !== null) {
                updateContactField($pdo, $conversationId, $field, $value);
            }
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges, $node['id'], $workflowId);
        }
        else if ($node['type'] === 'assign') {
            // Basic assignment logic stub
            log_debug("Assignment node reached (Logic pending)");
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges, $node['id'], $workflowId);
        }
        else {
            // Pass-through for unknown/unhandled nodes (e.g. Conditions, Questions, Delays)
            // If we don't handle them, we should at least try to continue to their children
            // so the workflow doesn't stop dead.
            // NOTE: Logic nodes like Conditions usually require evaluation.
            // Just passing through effectively treats them as "True" or "Next".
            log_debug("Unhandled Node Type: " . $node['type'] . ". Attempting to continue to children...");
            executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, $edges, $node['id'], $workflowId);
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

    if (!$user) {
        log_debug("Error: No user found for ID $userId to send workflow reply.");
        return;
    }

    $token = $user['whatsapp_access_token'];
    $phoneId = $user['whatsapp_phone_number_id'];

    if (!$token || !$phoneId) {
        log_debug("Error: Missing WhatsApp credentials for User ID $userId. Token or Phone ID is null.");
        return;
    }

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
        // Fallback to simple insert if provider_message_id is not yet in all code paths
        // But better to be consistent if possible.
        // For now, we use the simple insert as before
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, status) VALUES (?, 'agent', ?, ?, NOW(), 'sent')");
        $stmt->execute([$conversationId, $userId, $content]);
    } catch (Exception $e) {
        log_debug("Error saving workflow reply: " . $e->getMessage());
    }
}

function sendWorkflowQuestion($pdo, $userId, $conversationId, $node) {
    // 1. Get Settings
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return;

    $token = $user['whatsapp_access_token'];
    $phoneId = $user['whatsapp_phone_number_id'];

    // 2. Get Recipient
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) return;
    $to = preg_replace('/[^0-9]/', '', $contact['phone_number']);

    // 3. Construct Buttons
    $content = $node['content']; // Question text
    // Support multiple data keys for options (Frontend uses root 'options', backend might use 'data.options')
    $options = $node['options'] ?? ($node['data']['options'] ?? ($node['data']['quick_replies'] ?? []));

    // Ensure options is an array
    if (is_string($options)) {
        $options = array_map('trim', explode(',', $options));
    }

    // API Limitation: Max 3 buttons for 'button' type. If more, use 'list'.
    // For now, assume < 3 or handle first 3.
    $buttons = [];
    $i = 0;
    foreach ($options as $opt) {
        if ($i >= 3) break;
        $buttons[] = [
            'type' => 'reply',
            'reply' => [
                'id' => 'btn_' . uniqid(),
                'title' => substr($opt, 0, 20) // Limit title length
            ]
        ];
        $i++;
    }

    $url = "https://graph.facebook.com/v21.0/$phoneId/messages";

    if (count($buttons) > 0) {
        $data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $content],
                'action' => ['buttons' => $buttons]
            ]
        ];
    } else {
        // Fallback to text if no options
        $data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $content . "\n(Reply with: " . implode(', ', $options) . ")"]
        ];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    log_debug("Workflow Question Sent: " . $res);

    // 4. Save to DB
    try {
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, sent_at, status, message_type, interactive_data) VALUES (?, 'agent', ?, ?, NOW(), NOW(), 'sent', 'interactive', ?)");
        $stmt->execute([$conversationId, $userId, $content, json_encode($data['interactive'] ?? [])]);
    } catch (Exception $e) {
        log_debug("Error saving workflow question: " . $e->getMessage());
    }
}
?>
