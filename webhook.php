<?php
// webhook.php (Root Directory)
// Version: V3.6-WORKFLOW-AWARE
// Optimized for Real-world Schema Constraints (No user_id/tenant_id in conversations)

// 1. Polyfill for getallheaders()
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

// 2. Logging Setup (Multi-target for maximum visibility)
$debug_log_file_v1 = __DIR__ . '/webhook_debug.log';
$debug_log_file_v2 = __DIR__ . '/api/webhook_debug.log'; // Also try in api/

function log_debug($message) {
    global $debug_log_file_v1, $debug_log_file_v2;
    $timestamp = date('Y-m-d H:i:s');
    $msg = "[$timestamp] [V3.6] $message\n";

    // Try V1 (Root)
    try { file_put_contents($debug_log_file_v1, $msg, FILE_APPEND); } catch (Throwable $e) {}
    // Try V2 (API)
    try { file_put_contents($debug_log_file_v2, $msg, FILE_APPEND); } catch (Throwable $e) {}

    // Explicitly write to error_log without suppression to guarantee output somewhere
    error_log("[ChatMe Webhook] $message");
}

// Log generic entry
log_debug("Hit webhook.php | Method: " . $_SERVER['REQUEST_METHOD']);

// 3. Database Connection
$pdo = null;
try {
    require_once __DIR__ . '/api/db.php';
    if (file_exists(__DIR__ . '/api/config.php')) {
        require_once __DIR__ . '/api/config.php';
    }
} catch (Throwable $e) {
    log_debug("CRITICAL: DB Connection Failed: " . $e->getMessage());
    http_response_code(500);
    exit;
}

// --- 4. VERIFICATION (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    $verify_token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    // Use constant or fallback
    $defined_token = defined('WEBHOOK_VERIFY_TOKEN') ? WEBHOOK_VERIFY_TOKEN : 'ChatMeToken2025';

    if ($verify_token === $defined_token) {
        http_response_code(200);
        echo $challenge;
        log_debug("WhatsApp Webhook Verified.");
        exit;
    } else {
        http_response_code(403);
        log_debug("WhatsApp Verification Failed. Token mismatch.");
        exit;
    }
}

// --- 5. POST HANDLING ---
$body = file_get_contents('php://input');
if (empty($body)) {
    log_debug("Empty Body Received.");
    exit;
}

// Log Payload Stats
$body_len = strlen($body);
log_debug("Payload Received ($body_len bytes).");

$payload = json_decode($body, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    log_debug("JSON Decode Error: " . json_last_error_msg());
    exit;
}

// Respond 200 OK immediately for WhatsApp
if (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
    http_response_code(200);

    // CHECK FOR EMPTY ENTRY (The User's Problem)
    if (empty($payload['entry'])) {
        log_debug("WARNING: 'entry' is empty. Meta is sending an empty notification.");
        log_debug("ACTION REQUIRED: Go to Meta App Dashboard > WhatsApp > Configuration > Webhooks.");
        log_debug("ACTION REQUIRED: Ensure the 'messages' field is checked/subscribed.");
        exit;
    }

    try {
        foreach ($payload['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                $value = $change['value'];

                // --- TENANT IDENTIFICATION FIX ---
                $metadata = $value['metadata'] ?? null;
                $phone_number_id = $metadata['phone_number_id'] ?? null;

                if (!$phone_number_id) {
                    log_debug("Skipping: No phone_number_id in metadata.");
                    continue;
                }

                log_debug("Processing for Phone ID: [$phone_number_id]");

                // Robust Query: Trim and use prepared statement
                $stmt = $pdo->prepare("SELECT id, whatsapp_phone_number_id FROM users WHERE TRIM(whatsapp_phone_number_id) = ? LIMIT 1");
                $stmt->execute([trim((string)$phone_number_id)]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    log_debug("ERROR: No tenant found for Phone ID: [$phone_number_id]. Check 'users' table.");
                    continue;
                }

                // Map user_id (Tenant Owner) to tenant_id concept
                $user_id = (int)$user['id'];
                $tenant_id = $user_id; // Assume 1:1 mapping
                log_debug("Tenant Found: User ID $user_id");

                // --- HANDLE STATUS UPDATES (Delivered, Read) ---
                if (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $statusUpdate) {
                        $wamid = $statusUpdate['id']; // The WhatsApp Message ID
                        $status = $statusUpdate['status']; // sent, delivered, read

                        log_debug("Status Update: Msg $wamid is now $status");

                        try {
                            // Attempt to update status based on provider_message_id
                            // Schema migration in db.php ensures this column exists
                            $stmt_status = $pdo->prepare("UPDATE messages SET status = ? WHERE provider_message_id = ?");
                            $stmt_status->execute([$status, $wamid]);

                            if ($stmt_status->rowCount() > 0) {
                                log_debug("Updated DB status for Msg $wamid to $status");
                            } else {
                                log_debug("WARNING: Status update for $wamid to $status failed. No matching row found. (Rows: " . $stmt_status->rowCount() . ")");
                            }
                        } catch (PDOException $ex) {
                            log_debug("Status Update Failed: " . $ex->getMessage());
                        }
                    }
                }

                // Process Messages
                if (isset($value['messages'])) {
                    $messages = $value['messages'];
                    $contacts_data = $value['contacts'] ?? [];

                    foreach ($messages as $message) {
                        $wa_id = $message['from'];
                        $msg_type = $message['type'];

                        log_debug("Processing Message from: $wa_id Type: $msg_type");

                        // Extract Body
                        $msg_body = '';
                        if ($msg_type === 'text') {
                            $msg_body = $message['text']['body'];
                        } elseif ($msg_type === 'button') {
                            $msg_body = $message['button']['text'];
                        } elseif ($msg_type === 'image') {
                            $msg_body = "[Image]";
                            if (isset($message['image']['caption'])) $msg_body .= " " . $message['image']['caption'];
                        } elseif ($msg_type === 'document') {
                            $msg_body = "[Document] " . ($message['document']['filename'] ?? '');
                        } elseif ($msg_type === 'interactive') {
                             $type = $message['interactive']['type'];
                             if($type == 'button_reply') {
                                 // FIX: Extract only the title of the reply, not the full JSON object.
                                 $msg_body = $message['interactive']['button_reply']['title'] ?? '[Button Reply]';
                             } elseif($type == 'list_reply') {
                                 // FIX: Extract only the title of the selected list item.
                                 $msg_body = $message['interactive']['list_reply']['title'] ?? '[List Reply]';
                             } else {
                                 $msg_body = "[Interactive]";
                             }
                        } else {
                            $msg_body = "[" . ucfirst($msg_type) . "]";
                        }

                        // 1. Find Contact (Fuzzy Matching)
                        $normalized_phone = '+' . ltrim($wa_id, '+');
                        $last_9_digits = substr($wa_id, -9);

                        $contact_name = $normalized_phone;
                        foreach ($contacts_data as $c) {
                            if ($c['wa_id'] === $wa_id) {
                                $contact_name = $c['profile']['name'] ?? $normalized_phone;
                                break;
                            }
                        }

                        $stmt_contact = $pdo->prepare("SELECT id FROM contacts WHERE phone_number LIKE ? LIMIT 1");
                        $stmt_contact->execute(["%$last_9_digits"]);
                        $contact = $stmt_contact->fetch(PDO::FETCH_ASSOC);

                        if ($contact) {
                            $contact_id = $contact['id'];
                            log_debug("Found existing Contact ID: $contact_id");
                        } else {
                            // Create new contact - Try robust combinations including customer_id
                            try {
                                // Attempt 1: Tenant ID (Standard)
                                log_debug("Contact Create Attempt 1 (Tenant ID)...");
                                $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number, tenant_id, created_at) VALUES (?, ?, ?, NOW())");
                                $stmt_new_contact->execute([$contact_name, $normalized_phone, $tenant_id]);
                                $contact_id = $pdo->lastInsertId();
                            } catch (PDOException $e1) {
                                log_debug("Contact Attempt 1 failed: " . $e1->getMessage());
                                try {
                                    // Attempt 2: Tenant ID + Customer ID (Explicit NULL)
                                    log_debug("Contact Create Attempt 2 (Tenant + Customer NULL)...");
                                    $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number, tenant_id, customer_id, created_at) VALUES (?, ?, ?, NULL, NOW())");
                                    $stmt_new_contact->execute([$contact_name, $normalized_phone, $tenant_id]);
                                    $contact_id = $pdo->lastInsertId();
                                } catch (PDOException $e2) {
                                    log_debug("Contact Attempt 2 failed: " . $e2->getMessage());
                                    try {
                                        // Attempt 3: Tenant ID + Customer ID (Explicit 0)
                                        // This handles cases where customer_id is NOT NULL but has no default.
                                        log_debug("Contact Create Attempt 3 (Tenant + Customer 0)...");
                                        $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number, tenant_id, customer_id, created_at) VALUES (?, ?, ?, 0, NOW())");
                                        $stmt_new_contact->execute([$contact_name, $normalized_phone, $tenant_id]);
                                        $contact_id = $pdo->lastInsertId();
                                    } catch (PDOException $e3) {
                                        log_debug("Contact Attempt 3 failed: " . $e3->getMessage());
                                        try {
                                            // Attempt 4: Minimal (No Tenant, No Customer)
                                            log_debug("Contact Create Attempt 4 (Minimal)...");
                                            $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number, created_at) VALUES (?, ?, NOW())");
                                            $stmt_new_contact->execute([$contact_name, $normalized_phone]);
                                            $contact_id = $pdo->lastInsertId();
                                        } catch (PDOException $e4) {
                                            log_debug("CRITICAL: Contact creation failed. " . $e4->getMessage());
                                            continue; // Skip message if no contact
                                        }
                                    }
                                }
                            }
                            log_debug("Created new Contact ID: $contact_id");
                        }

                        // 2. Find/Create Conversation
                        $stmt_conv = $pdo->prepare("SELECT id, assigned_to, status FROM conversations WHERE contact_id = ? LIMIT 1");
                        $stmt_conv->execute([$contact_id]);
                        $conversation = $stmt_conv->fetch(PDO::FETCH_ASSOC);

                        if ($conversation) {
                            $conversation_id = $conversation['id'];
                            // Update Logic
                            $stmt_update = $pdo->prepare("UPDATE conversations SET updated_at = NOW(), last_message_preview = ?, status = 'open' WHERE id = ?");
                            $stmt_update->execute([$msg_body, $conversation_id]);
                            log_debug("Updated Conversation ID: $conversation_id");
                        } else {
                            // Create Logic - SCHEMA SIMPLIFIED

                            try {
                                // Attempt 1: Standard (Auto-assign to Tenant Owner)
                                log_debug("Conv Attempt 1 (Standard - Assigned to Owner)...");
                                $stmt_new_conv = $pdo->prepare("INSERT INTO conversations (contact_id, assigned_to, last_message_preview, status, updated_at, created_at) VALUES (?, ?, ?, 'open', NOW(), NOW())");
                                $stmt_new_conv->execute([$contact_id, $user_id, $msg_body]);
                                $conversation_id = $pdo->lastInsertId();
                            } catch (PDOException $e1) {
                                log_debug("Conv Attempt 1 Failed: " . $e1->getMessage());
                                try {
                                    // Attempt 2: Minimal (No assigned_to)
                                    log_debug("Conv Attempt 2 (Minimal)...");
                                    $stmt_new_conv = $pdo->prepare("INSERT INTO conversations (contact_id, last_message_preview, status, updated_at, created_at) VALUES (?, ?, 'open', NOW(), NOW())");
                                    $stmt_new_conv->execute([$contact_id, $msg_body]);
                                    $conversation_id = $pdo->lastInsertId();
                                } catch (PDOException $e2) {
                                    log_debug("CRITICAL: All conversation creation attempts failed. " . $e2->getMessage());
                                    continue;
                                }
                            }
                            log_debug("Created Conversation ID: $conversation_id");
                        }

                        // 3. Insert Message
                        try {
                            log_debug("Message Attempt 1 (Full)...");
                            $stmt_msg = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, tenant_id, content, created_at, sent_at, status) VALUES (?, 'contact', ?, ?, ?, NOW(), NOW(), 'received')");
                            $stmt_msg->execute([$conversation_id, $user_id, $tenant_id, $msg_body]);
                            log_debug("Message Saved! (With tenant_id)");
                        } catch (PDOException $e_msg) {
                            log_debug("Message Insert (Attempt 1) failed: " . $e_msg->getMessage());
                            try {
                                // Fallback: user_id only
                                log_debug("Message Attempt 2 (No tenant_id)...");
                                $stmt_msg = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, sent_at, status) VALUES (?, 'contact', ?, ?, NOW(), NOW(), 'received')");
                                $stmt_msg->execute([$conversation_id, $user_id, $msg_body]);
                                log_debug("Message Saved! (Without tenant_id)");
                            } catch (PDOException $e_msg2) {
                                log_debug("Message Insert (Attempt 2) failed: " . $e_msg2->getMessage());

                                // Fallback: minimal (No user_id - if schema is very simple)
                                try {
                                    log_debug("Message Attempt 3 (Minimal - No User ID)...");
                                    $stmt_msg = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, created_at, sent_at, status) VALUES (?, 'contact', ?, NOW(), NOW(), 'received')");
                                    $stmt_msg->execute([$conversation_id, $msg_body]);
                                    log_debug("Message Saved! (Minimal - No User ID)");
                                } catch (PDOException $e_msg3) {
                                    log_debug("Message Save Failed (All Attempts): " . $e_msg3->getMessage());
                                    log_debug("Debug Info: UserID=$user_id ConvID=$conversation_id");
                                }
                            }
                        }

                        // 4. Trigger Workflow Check
                        // Determine if this is a new conversation start
                        $isNewConversation = false;
                        try {
                            // Simply check if there is only 1 message in this conversation (the one just inserted)
                            $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ?");
                            $stmt_count->execute([$conversationId]);
                            if ($stmt_count->fetchColumn() <= 1) {
                                $isNewConversation = true;
                            }
                        } catch (Exception $e) {}

                        processWorkflows($pdo, $user_id, $conversationId, $msg_body, $isNewConversation);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        log_debug("EXCEPTION: " . $e->getMessage());
    }

    exit;
}

// --- WORKFLOW PROCESSING HELPER ---
function processWorkflows($pdo, $userId, $conversationId, $msgBody, $isNewConversation = false) {
    try {
        // Fetch active workflows
        // Robust check for is_active column existence handled by catch block if column missing in dev
        $stmt = $pdo->prepare("SELECT id, name, trigger_type, workflow_data FROM workflows WHERE is_active = 1");
        $stmt->execute();
        $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($workflows as $wf) {
            $triggerType = $wf['trigger_type'];
            $data = json_decode($wf['workflow_data'], true);
            $nodes = $data['nodes'] ?? [];

            $shouldTrigger = false;

            // 1. Conversation Started Trigger
            if ($triggerType === 'Conversation Started') {
                if ($isNewConversation) {
                    $shouldTrigger = true;
                }
            }
            // 2. Message Received Trigger (Always triggers on any message)
            else if ($triggerType === 'Message Received') {
                $shouldTrigger = true;
            }
            // 3. Keyword Trigger (Future implementation - strict matching)
            // For now, we assume standard triggers cover most needs

            if ($shouldTrigger) {
                log_debug("Workflow Triggered: " . $wf['name']);
                executeWorkflowRecursive($pdo, $userId, $conversationId, $nodes, null); // Start from root
                break; // Stop after first matching workflow to prevent conflicts
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
        // Note: 'Question' and 'Condition' nodes would require stopping and waiting for user input
        // or branching logic which is complex for a stateless webhook without session state tracking.
        // For this version, we only support linear execution of actions.
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

// --- 6. FLUTTERWAVE LOGIC (Legacy) ---
try {
    $headers = getallheaders();
    $is_flutterwave = isset($headers['Verif-Hash']);

    if ($is_flutterwave) {
        $signature = $headers['Verif-Hash'] ?? null;
        $tenant_id = $_GET['tenant_id'] ?? null;

        if (!$tenant_id || !isset($pdo)) {
            http_response_code(400);
            exit();
        }

        $stmt = $pdo->prepare("SELECT flw_webhook_secret_hash FROM users WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $local_secret_hash = $user['flw_webhook_secret_hash'] ?? null;

        if (!$local_secret_hash || $signature !== $local_secret_hash) {
            http_response_code(401);
            exit();
        }

        if ($payload && isset($payload['event']) && $payload['event'] === 'charge.completed' && isset($payload['data']['status']) && $payload['data']['status'] === 'successful') {
            $transaction_ref = $payload['data']['tx_ref'] ?? null;
            $amount_paid = $payload['data']['amount'] ?? 0;

            if ($transaction_ref) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ? AND tenant_id = ?");
                    $stmt->execute([$transaction_ref, $tenant_id]);
                    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($invoice) {
                        $invoice_id = $invoice['id'];
                        $stmt_payment = $pdo->prepare("INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, notes, tenant_id) VALUES (?, ?, ?, 'Flutterwave', ?, ?)");
                        $stmt_payment->execute([$invoice_id, $amount_paid, date('Y-m-d'), "TXN Ref: " . $transaction_ref, $tenant_id]);

                        $new_amount_paid = $invoice['amount_paid'] + $amount_paid;
                        $new_status = ($new_amount_paid >= $invoice['total_amount']) ? 'Paid' : 'Partially Paid';
                        $stmt_update = $pdo->prepare("UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ? AND tenant_id = ?");
                        $stmt_update->execute([$new_amount_paid, $new_status, $invoice_id, $tenant_id]);

                        $pdo->commit();
                        http_response_code(200);
                    } else {
                        $pdo->rollBack();
                        http_response_code(404);
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    http_response_code(500);
                }
            } else {
                http_response_code(400);
            }
        } else {
            http_response_code(200);
        }
    } else {
        http_response_code(200);
    }
} catch (Throwable $e) {
    log_debug("Legacy/Flutterwave Error: " . $e->getMessage());
    http_response_code(500);
}
?>
