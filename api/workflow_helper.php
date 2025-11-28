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
                    // No specific options defined, any reply is valid? Or simple Yes/No?
                    // If options empty, accept any text and continue.
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
                    // User answered correctly. Move to NEXT step.
                    // Note: Ideally we might want to branch based on answer, but requirement is "If answered YES, go to next step"
                    // For now, simple linear: If Match, Next. If No Match, maybe send fallback or do nothing.
                    // Let's just proceed.
                    log_debug("Reply matched. Proceeding to next step.");
                    clearWorkflowState($pdo, $conversationId); // Clear wait state
                    executeWorkflowSteps($pdo, $state['workflow_id'], $userId, $conversationId, $state['step_order'] + 1);
                    return; // Stop trigger processing
                } else {
                    // No match. Do nothing? Or re-send question?
                    // For now, let's do nothing, or user might be trying to break out.
                    // If we return, we block other triggers.
                    // Let's return to keep them "Trapped" in the question until resolved or expired?
                    // Or let other triggers fire?
                    // Usually, explicit workflow state blocks other triggers.
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
                // Start from Step 1 (Order 1)
                executeWorkflowSteps($pdo, $wf['id'], $userId, $conversationId, 1);
                break;
            }
        }

    } catch (Exception $e) {
        log_debug("Workflow Error: " . $e->getMessage());
    }
}

function executeWorkflowSteps($pdo, $workflowId, $userId, $conversationId, $startStepOrder = 1) {
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

            log_debug("Executing Step $order: $action");

            if ($action === 'SEND_MESSAGE') {
                if ($meta['type'] === 'template' && !empty($meta['template_id'])) {
                    sendWorkflowTemplate($pdo, $userId, $conversationId, $meta['template_id']);
                } else {
                    if (!empty($step['content'])) {
                        sendWorkflowReply($pdo, $userId, $conversationId, $step['content']);
                    }
                }
            }
            elseif ($action === 'ASSIGN_AGENT') {
                assignAgentLogic($pdo, $conversationId, $step['content']);
            }
            elseif ($action === 'ADD_TAG') {
                if (!empty($step['content'])) {
                    addContactTag($pdo, $conversationId, $step['content']);
                }
            }
            elseif ($action === 'ASK_QUESTION') {
                // 1. Send the Question (Interactive)
                sendWorkflowQuestion($pdo, $userId, $conversationId, $step['content'], $meta['options'] ?? '');

                // 2. PAUSE Execution (Wait for Reply)
                saveWorkflowState($pdo, $conversationId, $workflowId, $order, 'WAITING_FOR_REPLY');
                log_debug("Paused Workflow at Step $order (Waiting for Reply)");
                return; // STOP HERE
            }
            elseif ($action === 'DELAY') {
                $minutes = (int)($step['content'] ?? 0);
                if ($minutes > 0) {
                    $resumeAt = date('Y-m-d H:i:s', strtotime("+$minutes minutes"));
                    saveWorkflowState($pdo, $conversationId, $workflowId, $order + 1, 'DELAYED', $resumeAt);
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
    return $json ? json_decode($json, true) : null;
}

function saveWorkflowState($pdo, $conversationId, $workflowId, $stepOrder, $status, $resumeAt = null) {
    $state = [
        'workflow_id' => $workflowId,
        'step_order' => $stepOrder,
        'status' => $status,
        'resume_at' => $resumeAt,
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
