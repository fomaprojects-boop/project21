<?php
// webhook.php (Root Directory)

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
$log_file = __DIR__ . '/webhook_log.txt';
$debug_log_file = __DIR__ . '/webhook_debug.log';

function log_debug($message) {
    global $debug_log_file;
    file_put_contents($debug_log_file, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
}

// 3. Database Connection
try {
    require_once __DIR__ . '/api/db.php';
    require_once __DIR__ . '/api/config.php';
} catch (Throwable $e) {
    log_debug("CRITICAL: DB/Config load failed: " . $e->getMessage());
    // Return 200 to Meta even if we fail, to stop retries
    http_response_code(200);
    // We might want to exit if DB is critical, but let's see if we can proceed or just stop.
    // Without DB, we can't do much.
    exit;
}

// Log ANY Request immediately to debug connectivity
file_put_contents($debug_log_file, "Hit webhook.php at " . date('Y-m-d H:i:s') . " | Method: " . $_SERVER['REQUEST_METHOD'] . "\n", FILE_APPEND);

$headers = getallheaders();
file_put_contents($debug_log_file, "Headers: " . print_r($headers, true) . "\n", FILE_APPEND);

// --- 4. WHATSAPP VERIFICATION (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    $verify_token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if (defined('WEBHOOK_VERIFY_TOKEN') && $verify_token === WEBHOOK_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        log_debug("WhatsApp Webhook Verified.");
        exit();
    } else {
        http_response_code(403);
        log_debug("WhatsApp Verification Failed. Token mismatch.");
        exit();
    }
}

// --- 5. INBOUND MESSAGES (POST) ---
$body = file_get_contents('php://input');
if (empty($body)) {
    exit;
}

// Log raw payload
file_put_contents($debug_log_file, "Received POST: " . date('Y-m-d H:i:s') . "\n" . substr($body, 0, 1000) . "\n----------------\n", FILE_APPEND);

$payload = json_decode($body, true);

// Respond 200 OK immediately for WhatsApp to acknowledge receipt
// This prevents "1-tick" issues where Meta retries if we take too long.
// We do this conditionally if we detect it's likely a WhatsApp payload, or just generally if we handle it.
// Meta requires 200 OK. Flutterwave also expects 200 OK.

// Check if it's a WhatsApp payload
if (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
    http_response_code(200);

    try {
        log_debug("Processing WhatsApp Payload...");

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
                        $wa_id = $message['from']; // The sender's raw ID

                        // Determine Message Content and Type
                        $msg_type = $message['type'];
                        $msg_body = '';

                        if ($msg_type === 'text') {
                            $msg_body = $message['text']['body'];
                        } elseif ($msg_type === 'button') {
                            $msg_body = $message['button']['text'];
                        } elseif ($msg_type === 'image') {
                            $msg_body = "[Image]";
                        } elseif ($msg_type === 'document') {
                            $msg_body = "[Document] " . ($message['document']['filename'] ?? '');
                        } else {
                            $msg_body = "[" . ucfirst($msg_type) . "]";
                        }

                        // 1. Find or Create Contact
                        // Normalize phone number for storage
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
                        } else {
                            $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number, created_at) VALUES (?, ?, NOW())");
                            $stmt_new_contact->execute([$contact_name, $normalized_phone]);
                            $contact_id = $pdo->lastInsertId();
                            log_debug("Created new contact ID: $contact_id");
                        }

                        // 2. Find or Create Conversation
                        $stmt_conv = $pdo->prepare("SELECT id FROM conversations WHERE contact_id = ? LIMIT 1");
                        $stmt_conv->execute([$contact_id]);
                        $conversation = $stmt_conv->fetch(PDO::FETCH_ASSOC);

                        $conversation_id = null;
                        if ($conversation) {
                            $conversation_id = $conversation['id'];
                            // Update last message preview and timestamp
                            $stmt_update = $pdo->prepare("UPDATE conversations SET updated_at = NOW(), last_message_preview = ? WHERE id = ?");
                            $stmt_update->execute([$msg_body, $conversation_id]);
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
                // Handle Status Updates
                elseif (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        log_debug("Message Status Update: " . $status['status'] . " for ID " . $status['id']);
                    }
                }
            }
        }

    } catch (Exception $e) {
        log_debug("EXCEPTION processing WhatsApp: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    exit(); // Stop here, we are done with WhatsApp
}

// --- 6. FLUTTERWAVE LOGIC (Legacy) ---
// Only run if NOT WhatsApp
$is_flutterwave = isset($headers['Verif-Hash']);

if ($is_flutterwave) {
    $signature = $headers['Verif-Hash'] ?? null;
    $tenant_id = $_GET['tenant_id'] ?? null;

    if (!$tenant_id || !isset($pdo)) {
        http_response_code(400);
        exit();
    }

    // Fetch tenant secret
    $stmt = $pdo->prepare("SELECT flw_webhook_secret_hash FROM users WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $local_secret_hash = $user['flw_webhook_secret_hash'] ?? null;

    if (!$local_secret_hash || $signature !== $local_secret_hash) {
        http_response_code(401);
        exit();
    }

    // ... existing logic ...
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
    // Default response for unknown payloads to keep healthy status
    http_response_code(200);
}
?>
