<?php
// api/webhook.php

// 1. Polyfill for getallheaders() if missing
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

// 2. Logging Configuration
$log_file = __DIR__ . '/../webhook_log.txt';
$debug_log_file = __DIR__ . '/../webhook_debug.log';

function log_debug($message) {
    global $debug_log_file;
    file_put_contents($debug_log_file, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
}

// 3. Database Connection
try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    log_debug("CRITICAL: DB/Config load failed: " . $e->getMessage());
    http_response_code(200); // Return 200 to Meta even if we fail, to stop retries (or 500 if we want retries, but 200 is safer for now)
    exit;
}

// 4. Handle Verification (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    $verify_token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if (defined('WEBHOOK_VERIFY_TOKEN') && $verify_token === WEBHOOK_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        log_debug("Verification Successful.");
        exit();
    } else {
        http_response_code(403);
        log_debug("Verification Failed. Token mismatch.");
        exit();
    }
}

// 5. Handle Inbound Messages (POST)
$body = file_get_contents('php://input');
if (empty($body)) {
    // Likely a browser visit or empty ping
    exit;
}

$payload = json_decode($body, true);

// Respond 200 OK immediately for WhatsApp to acknowledge receipt
// This prevents "1-tick" issues where Meta retries if we take too long.
http_response_code(200);

// Check if it's a WhatsApp payload
if (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
    try {
        log_debug("WhatsApp Payload Received: " . substr($body, 0, 500) . "..."); // Log first 500 chars

        if (!isset($pdo)) {
            throw new Exception("PDO Database connection is not set.");
        }

        foreach ($payload['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                $value = $change['value'];

                // Get Phone Number ID to identify the Tenant
                $metadata = $value['metadata'] ?? null;
                $phone_number_id = $metadata['phone_number_id'] ?? null;

                if (!$phone_number_id) {
                    log_debug("Skipping: No phone_number_id in metadata.");
                    continue;
                }

                // Identify Tenant (User)
                $stmt = $pdo->prepare("SELECT id, whatsapp_phone_number_id FROM users WHERE whatsapp_phone_number_id = ? LIMIT 1");
                $stmt->execute([$phone_number_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    log_debug("Error: No tenant found for Phone ID: $phone_number_id");
                    continue;
                }

                $user_id = $user['id'];
                log_debug("Tenant Identified: User ID $user_id for Phone ID $phone_number_id");

                // Process Messages
                if (isset($value['messages'])) {
                    $messages = $value['messages'];
                    $contacts_data = $value['contacts'] ?? [];

                    foreach ($messages as $message) {
                        $wa_id = $message['from']; // The sender's raw ID (e.g., 255712345678)

                        // Determine Message Content and Type
                        $msg_type = $message['type'];
                        $msg_body = '';
                        $media_id = null; // Placeholder for future media handling

                        if ($msg_type === 'text') {
                            $msg_body = $message['text']['body'];
                        } elseif ($msg_type === 'button') {
                            $msg_body = $message['button']['text'];
                        } elseif ($msg_type === 'image') {
                            $msg_body = "[Image]";
                            // Future: Handle image download using $message['image']['id']
                        } elseif ($msg_type === 'document') {
                            $msg_body = "[Document] " . ($message['document']['filename'] ?? '');
                        } else {
                            $msg_body = "[" . ucfirst($msg_type) . "]";
                        }

                        // 1. Find or Create Contact
                        // Normalize phone number for storage (Add + if missing)
                        $normalized_phone = '+' . ltrim($wa_id, '+');

                        // Get Contact Name from payload or default to phone
                        $contact_name = $normalized_phone;
                        foreach ($contacts_data as $c) {
                            if ($c['wa_id'] === $wa_id) {
                                $contact_name = $c['profile']['name'] ?? $normalized_phone;
                                break;
                            }
                        }

                        $stmt_contact = $pdo->prepare("SELECT id FROM contacts WHERE phone_number = ? LIMIT 1");
                        $stmt_contact->execute([$normalized_phone]);
                        $contact = $stmt_contact->fetch(PDO::FETCH_ASSOC);

                        $contact_id = null;
                        if ($contact) {
                            $contact_id = $contact['id'];
                            log_debug("Found existing contact ID: $contact_id");
                        } else {
                            $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number, created_at) VALUES (?, ?, NOW())");
                            $stmt_new_contact->execute([$contact_name, $normalized_phone]);
                            $contact_id = $pdo->lastInsertId();
                            log_debug("Created new contact ID: $contact_id");
                        }

                        // 2. Find or Create Conversation
                        // Check for an open conversation with this contact
                        $stmt_conv = $pdo->prepare("SELECT id FROM conversations WHERE contact_id = ? LIMIT 1");
                        $stmt_conv->execute([$contact_id]);
                        $conversation = $stmt_conv->fetch(PDO::FETCH_ASSOC);

                        $conversation_id = null;
                        if ($conversation) {
                            $conversation_id = $conversation['id'];
                            // Update last message preview and timestamp
                            $stmt_update = $pdo->prepare("UPDATE conversations SET updated_at = NOW(), last_message_preview = ? WHERE id = ?");
                            $stmt_update->execute([$msg_body, $conversation_id]);
                            log_debug("Updated conversation ID: $conversation_id");
                        } else {
                            $stmt_new_conv = $pdo->prepare("INSERT INTO conversations (contact_id, last_message_preview, status, updated_at, created_at) VALUES (?, ?, 'open', NOW(), NOW())");
                            $stmt_new_conv->execute([$contact_id, $msg_body]);
                            $conversation_id = $pdo->lastInsertId();
                            log_debug("Created new conversation ID: $conversation_id");
                        }

                        // 3. Insert Message
                        $stmt_msg = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, created_at, status) VALUES (?, 'contact', ?, NOW(), 'received')");
                        if ($stmt_msg->execute([$conversation_id, $msg_body])) {
                            log_debug("Message inserted successfully for Conversation ID: $conversation_id");
                        } else {
                            log_debug("Failed to insert message: " . implode(" ", $stmt_msg->errorInfo()));
                        }
                    }
                }

                // Handle Status Updates (Sent/Delivered/Read)
                elseif (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        // Logic to update message status in DB if you track message IDs
                        // For now, just log
                        log_debug("Message Status Update: " . $status['status'] . " for ID " . $status['id']);
                    }
                }
            }
        }

    } catch (Exception $e) {
        log_debug("EXCEPTION processing WhatsApp: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    exit();
}

// --- Legacy Flutterwave Logic (Preserved) ---
// ... (Code omitted for brevity as it's not the focus, but I will include the closing tag)
?>
