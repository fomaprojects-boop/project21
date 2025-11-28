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
// $contextData can contain: 'msg_body', 'event_type' ('message_received', 'conversation_started', 'conversation_closed'), 'tag_name'
function processWorkflows($pdo, $userId, $conversationId, $contextData = []) {
    try {
        $msgBody = trim($contextData['msg_body'] ?? '');
        $eventType = strtoupper($contextData['event_type'] ?? 'MESSAGE_RECEIVED');
        if ($eventType === 'MESSAGE_RECEIVED') {} // Normalize event type

        log_debug("Processing workflows. Event: $eventType");

        // --- STEP 1: CHECK FOR RESUMABLE STATE (Wait for Reply) ---
        // Only relevant if event is MESSAGE_RECEIVED (User replying)
        if ($eventType === 'MESSAGE_RECEIVED' || $eventType === 'message_received') {
            $state = getWorkflowState($pdo, $conversationId);
            if ($state && $state['status'] === 'WAITING_FOR_REPLY') {
                log_debug("Resuming Workflow {$state['workflow_id']} at Step {$state['step_order']}. Reply: $msgBody");

                // Validate Reply (Is it one of the options?)
                // We fetch the CURRENT step to check options
                $currentStep = getWorkflowStep($pdo, $state['workflow_id'], $state['step_order']);
                $meta = json_decode($currentStep['meta_data'] ?? '{}', true);
                $options = isset($meta['options']) ? array_map('trim', explode(',', $meta['options'])) : [];

                $matchFound = false;
                if (empty($options)) {
                    // No specific options defined, any reply is valid.
                    $matchFound = true;
                } else {
                     foreach ($options as $opt) {
                         if (strcasecmp($opt, $msgBody) === 0) {
                             $matchFound = true;
                             break;
                         }
                     }
                }

                if ($matchFound) {
                    // 2. Capture Data (If variable is defined)
                    $collectedData = isset($state['collected_data']) ? $state['collected_data'] : [];
                    if (!empty($meta['variable'])) {
                        $variableName = trim($meta['variable']);
                        $collectedData[$variableName] = $msgBody;
                        log_debug("Captured Variable '$variableName' = '$msgBody'");
                    }

                    // 3. Proceed to Next Step
                    log_debug("Reply matched/accepted. Proceeding to next step.");
                    clearWorkflowState($pdo, $conversationId); // Clear wait state
                    executeWorkflowSteps($pdo, $state['workflow_id'], $userId, $conversationId, $state['step_order'] + 1, $collectedData);
                    return; // Stop trigger processing
                } else {
                    // No match. Do nothing.
                    log_debug("Reply did not match expected options (" . implode(',', $options) . "). Ignoring.");
                    return;
                }
            }
        }

        // --- STEP 2: CHECK FOR NEW TRIGGERS ---
        $stmt = $pdo->prepare("SELECT id, name, trigger_type, keywords FROM workflows WHERE is_active = 1");
        $stmt->execute();
        $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($workflows as $wf) {
            $triggerType = strtoupper($wf['trigger_type']);
            $shouldTrigger = false;

            // 1. KEYWORD MATCH (MESSAGE_RECEIVED)
            if ($triggerType === 'KEYWORD' && ($eventType === 'MESSAGE_RECEIVED' || $eventType === 'message_received') && !empty($msgBody)) {
                $keywords = array_map('trim', explode(',', $wf['keywords']));
                foreach ($keywords as $keyword) {
                    if (empty($keyword)) continue;
                    if (stripos($msgBody, $keyword) !== false) {
                        $shouldTrigger = true;
                        break;
                    }
                }
            }
            // 2. CONVERSATION STARTED
            elseif ($triggerType === 'CONVERSATION_STARTED' && $eventType === 'CONVERSATION_STARTED') {
                $shouldTrigger = true;
            }
            // 3. CONVERSATION CLOSED
            elseif ($triggerType === 'CONVERSATION_CLOSED' && $eventType === 'CONVERSATION_CLOSED') {
                $shouldTrigger = true;
            }
            // 4. PAYMENT RECEIVED
            elseif ($triggerType === 'PAYMENT_RECEIVED' && $eventType === 'PAYMENT_RECEIVED') {
                $shouldTrigger = true;
            }
            // 5. TAG ADDED
            elseif ($triggerType === 'TAG_ADDED' && $eventType === 'TAG_ADDED') {
                $targetTag = trim($wf['keywords']);
                $addedTag = trim($contextData['tag_name'] ?? '');
                if (strcasecmp($targetTag, $addedTag) === 0) {
                    $shouldTrigger = true;
                }
            }

            if ($shouldTrigger) {
                log_debug("Triggering Workflow ID: {$wf['id']} Name: {$wf['name']}");
                // Start from Step 1 (Order 1), Empty collected data
                executeWorkflowSteps($pdo, $wf['id'], $userId, $conversationId, 1, []);
                break;
            }
        }

    } catch (Exception $e) {
        log_debug("Workflow Error: " . $e->getMessage());
    }
}

function executeWorkflowSteps($pdo, $workflowId, $userId, $conversationId, $startStepOrder = 1, $collectedData = []) {
    try {
        // Fetch steps ordered by step_order starting from current
        $stmt = $pdo->prepare("SELECT step_order, action_type, content, meta_data FROM workflow_steps WHERE workflow_id = ? AND step_order >= ? ORDER BY step_order ASC");
        $stmt->execute([$workflowId, $startStepOrder]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($steps)) {
            log_debug("Workflow ID $workflowId completed or no steps found.");
            // Ensure state is clear if we finished
            clearWorkflowState($pdo, $conversationId);
            return;
        }

        foreach ($steps as $step) {
            $action = $step['action_type'];
            $order = $step['step_order'];
            $meta = json_decode($step['meta_data'] ?? '{}', true);

            // Resolve Variables in Content
            $finalContent = resolveWorkflowVariables($pdo, $conversationId, $step['content'], $collectedData);

            log_debug("Executing Step $order: $action");

            if ($action === 'SEND_MESSAGE') {
                if ($meta['type'] === 'template' && !empty($meta['template_id'])) {
                    sendWorkflowTemplate($pdo, $userId, $conversationId, $meta['template_id']);
                } else {
                    if (!empty($finalContent)) {
                        sendWorkflowReply($pdo, $userId, $conversationId, $finalContent);
                    }
                }
            }
            elseif ($action === 'ASSIGN_AGENT') {
                assignAgentLogic($pdo, $conversationId, $finalContent);
            }
            elseif ($action === 'ADD_TAG') {
                if (!empty($finalContent)) {
                    addContactTag($pdo, $conversationId, $finalContent);
                }
            }
            elseif ($action === 'UPDATE_CONTACT') {
                updateContactFromWorkflow($pdo, $conversationId, $collectedData);
            }
            elseif ($action === 'CREATE_JOB_ORDER') {
                createJobOrderFromWorkflow($pdo, $conversationId, $userId, $collectedData);
            }
            elseif ($action === 'ASK_QUESTION') {
                // 1. Send the Question (Interactive)
                sendWorkflowQuestion($pdo, $userId, $conversationId, $finalContent, $meta['options'] ?? '');

                // 2. PAUSE Execution (Wait for Reply)
                // Pass collectedData to persist it
                saveWorkflowState($pdo, $conversationId, $workflowId, $order, 'WAITING_FOR_REPLY', null, $collectedData);
                log_debug("Paused Workflow at Step $order (Waiting for Reply)");
                return; // STOP HERE
            }
            elseif ($action === 'DELAY') {
                $minutes = (int)($step['content'] ?? 0);
                if ($minutes > 0) {
                    $resumeAt = date('Y-m-d H:i:s', strtotime("+$minutes minutes"));
                    saveWorkflowState($pdo, $conversationId, $workflowId, $order + 1, 'DELAYED', $resumeAt, $collectedData);
                    log_debug("Paused Workflow at Step $order. Resuming at Step " . ($order+1) . " on $resumeAt");
                    return; // STOP HERE
                }
            }
        }

        // If loop finishes, workflow is done.
        clearWorkflowState($pdo, $conversationId);

    } catch (Exception $e) {
        log_debug("Step Execution Error: " . $e->getMessage());
    }
}

// --- STATE MANAGEMENT HELPERS ---

function getWorkflowState($pdo, $conversationId) {
    $stmt = $pdo->prepare("SELECT workflow_state FROM conversations WHERE id = ?");
    $stmt->execute([$conversationId]);
    $json = $stmt->fetchColumn();
    // Assuming workflow_state stores collected_data
    return $json ? json_decode($json, true) : null;
}

function saveWorkflowState($pdo, $conversationId, $workflowId, $stepOrder, $status, $resumeAt = null, $collectedData = []) {
    $state = [
        'workflow_id' => $workflowId,
        'step_order' => $stepOrder,
        'status' => $status,
        'resume_at' => $resumeAt,
        'collected_data' => $collectedData,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    $stmt = $pdo->prepare("UPDATE conversations SET workflow_state = ? WHERE id = ?");
    $stmt->execute([json_encode($state), $conversationId]);
}

function clearWorkflowState($pdo, $conversationId) {
    $stmt = $pdo->prepare("UPDATE conversations SET workflow_state = NULL WHERE id = ?");
    $stmt->execute([$conversationId]);
}

function getWorkflowStep($pdo, $workflowId, $stepOrder) {
    $stmt = $pdo->prepare("SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order = ?");
    $stmt->execute([$workflowId, $stepOrder]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


// --- ACTION IMPLEMENTATIONS ---

function sendWorkflowReply($pdo, $userId, $conversationId, $content) {
    $creds = getUserCredentials($pdo, $userId);
    if (!$creds) return;
    $token = $creds['whatsapp_access_token'];
    $phoneId = $creds['whatsapp_phone_number_id'];

    $to = getRecipientPhone($pdo, $conversationId);
    if (!$to) return;

    // Content already resolved in executeWorkflowSteps, but doing it again harmlessly if no vars
    // No, we passed resolved content.

    $url = "https://graph.facebook.com/v21.0/$phoneId/messages";
    $data = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $content]
    ];

    executeMetaApi($pdo, $url, $token, $data, $conversationId, $userId, $content);
}

function sendWorkflowTemplate($pdo, $userId, $conversationId, $templateName) {
    $creds = getUserCredentials($pdo, $userId);
    if (!$creds) return;
    $token = $creds['whatsapp_access_token'];
    $phoneId = $creds['whatsapp_phone_number_id'];

    $to = getRecipientPhone($pdo, $conversationId);
    if (!$to) return;

    $url = "https://graph.facebook.com/v21.0/$phoneId/messages";
    $data = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'template',
        'template' => [
            'name' => $templateName,
            'language' => ['code' => 'en_US'] // Defaulting to English for now
        ]
    ];

    executeMetaApi($pdo, $url, $token, $data, $conversationId, $userId, "[Template: $templateName]");
}

function sendWorkflowQuestion($pdo, $userId, $conversationId, $questionText, $optionsStr) {
    $creds = getUserCredentials($pdo, $userId);
    if (!$creds) return;
    $token = $creds['whatsapp_access_token'];
    $phoneId = $creds['whatsapp_phone_number_id'];

    $to = getRecipientPhone($pdo, $conversationId);
    if (!$to) return;

    // Content already resolved

    $options = array_map('trim', explode(',', $optionsStr));
    $buttons = [];
    $i = 0;
    foreach ($options as $opt) {
        if (empty($opt)) continue;
        if ($i >= 3) break; // Max 3 buttons
        $buttons[] = [
            'type' => 'reply',
            'reply' => [
                'id' => 'wf_btn_' . uniqid(),
                'title' => substr($opt, 0, 20)
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
                'body' => ['text' => $questionText],
                'action' => ['buttons' => $buttons]
            ]
        ];
    } else {
        // Fallback if no options provided
        $data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $questionText]
        ];
    }

    executeMetaApi($pdo, $url, $token, $data, $conversationId, $userId, $questionText);
}

// --- NEW ACTIONS ---

function updateContactFromWorkflow($pdo, $conversationId, $data) {
    try {
        $stmt = $pdo->prepare("SELECT contact_id FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $contactId = $stmt->fetchColumn();
        if (!$contactId) return;

        $updates = [];
        $params = [];

        // Update Name
        if (!empty($data['name'])) {
            $updates[] = "name = ?";
            $params[] = $data['name'];
        }
        // Update Email
        if (!empty($data['email'])) {
            $updates[] = "email = ?";
            $params[] = $data['email'];
        }

        if (!empty($updates)) {
            $params[] = $contactId;
            $sql = "UPDATE contacts SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            log_debug("Updated Contact $contactId info via Workflow.");
        }
    } catch (Exception $e) {
        log_debug("Update Contact Error: " . $e->getMessage());
    }
}

function createJobOrderFromWorkflow($pdo, $conversationId, $userId, $data) {
    try {
        log_debug("Starting CREATE_JOB_ORDER for Conversation $conversationId");

        // 1. Resolve Contact ID
        $stmt = $pdo->prepare("SELECT contact_id FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $contactId = $stmt->fetchColumn();

        // Use contact_id as customer_id
        $customerId = $contactId ?: 0;

        // 2. Resolve Data
        $size = $data['size'] ?? 'N/A';
        $quantity = intval($data['quantity'] ?? 1);
        $material = $data['material'] ?? 'Standard';
        $address = $data['address'] ?? 'Not provided';
        $notes = "Order via Workflow.\nName: " . ($data['name']??'') . "\nAddress: $address\nNotes: " . ($data['notes']??'');

        $trackingNumber = 'J' . time() . rand(100, 999);
        $costPrice = 0;
        $sellingPrice = 0;

        // 3. Assign to a Staff Member (Random Round Robin)
        // This ensures the order has an 'assigned_to' user, visible in dashboards
        $staffStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'Staff' ORDER BY RAND() LIMIT 1");
        $staffStmt->execute();
        $assignedToId = $staffStmt->fetchColumn();

        // If no staff found, assign to current user (likely admin) or 0
        if (!$assignedToId) {
             $assignedToId = $userId;
        }

        log_debug("Assigning Job $trackingNumber to Staff ID: $assignedToId");

        // 4. Insert into Job Orders
        // Explicitly setting assigned_to and status
        $stmt = $pdo->prepare(
            "INSERT INTO job_orders (
                customer_id, tracking_number, size, quantity, material, notes,
                cost_price, selling_price, status, assigned_to, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, 'Pending', ?, NOW()
            )"
        );

        $stmt->execute([
            $customerId, $trackingNumber, $size, $quantity, $material, $notes,
            $costPrice, $sellingPrice, $assignedToId
        ]);

        $jobId = $pdo->lastInsertId();

        log_debug("SUCCESS: Created Job Order #$jobId ($trackingNumber) for Customer $customerId");

    } catch (Exception $e) {
        log_debug("Create Job Order Error: " . $e->getMessage());
    }
}

// --- VARIABLE RESOLVER ---

function resolveWorkflowVariables($pdo, $conversationId, $text, $collectedData = []) {
    if (strpos($text, '{{') === false) return $text; // Optimization

    try {
        // 1. Resolve Local Collected Variables
        foreach ($collectedData as $key => $val) {
            if (is_string($val) || is_numeric($val)) {
                $text = str_replace('{{' . $key . '}}', $val, $text);
            }
        }

        // If no more variables, return early
        if (strpos($text, '{{') === false) return $text;

        // 2. Fetch Contact and Agent Info (Global Context)
        $stmt = $pdo->prepare("
            SELECT
                c.name as contact_name,
                c.phone_number as contact_phone,
                u.full_name as agent_name
            FROM conversations conv
            JOIN contacts c ON conv.contact_id = c.id
            LEFT JOIN users u ON conv.assigned_to = u.id
            WHERE conv.id = ?
        ");
        $stmt->execute([$conversationId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) return $text;

        $customerName = !empty($data['contact_name']) ? $data['contact_name'] : $data['contact_phone'];
        $agentName = !empty($data['agent_name']) ? $data['agent_name'] : 'Support Agent';

        // Replacements
        $text = str_replace('{{customer_name}}', $customerName, $text);
        $text = str_replace('{{agent_name}}', $agentName, $text);

        return $text;

    } catch (Exception $e) {
        log_debug("Variable Resolution Error: " . $e->getMessage());
        return $text;
    }
}

// --- UTILS ---

function getUserCredentials($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !$user['whatsapp_access_token']) {
        log_debug("Missing Credentials for User $userId");
        return null;
    }
    return $user;
}

function getRecipientPhone($pdo, $conversationId) {
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$contact) return null;
    return preg_replace('/[^0-9]/', '', $contact['phone_number']);
}

function executeMetaApi($pdo, $url, $token, $data, $conversationId, $userId, $contentForDB) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_debug("Meta API Status: $httpCode. Resp: " . substr($response, 0, 50));

    if ($httpCode == 200) {
        try {
            $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, status) VALUES (?, 'agent', ?, ?, NOW(), 'sent')");
            $stmt->execute([$conversationId, $userId, $contentForDB]);
        } catch (Exception $e) {
            log_debug("DB Save Error: " . $e->getMessage());
        }
    }
}

function addContactTag($pdo, $conversationId, $tag) {
    try {
        $stmt = $pdo->prepare("SELECT contact_id FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $contactId = $stmt->fetchColumn();
        if (!$contactId) return;

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
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('Admin', 'Staff')");
        $stmt->execute();
        $agents = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($agents)) return;
        $assignedId = $agents[array_rand($agents)]; // Simple Random

        if ($assignedId) {
            $stmt = $pdo->prepare("UPDATE conversations SET assigned_to = ? WHERE id = ?");
            $stmt->execute([$assignedId, $conversationId]);
            log_debug("Assigned Conversation $conversationId to Agent $assignedId");
        }
    } catch (Exception $e) {
        log_debug("Assign Agent Error: " . $e->getMessage());
    }
}
?>
