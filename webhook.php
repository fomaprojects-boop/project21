<?php
// webhook.php (Root Directory)
// Optimized for Robustness & Debugging

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

// 2. Logging Setup
$debug_log_file = __DIR__ . '/webhook_debug.log';

function log_debug($message) {
    global $debug_log_file;
    try {
        file_put_contents($debug_log_file, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
    } catch (Throwable $e) {
        // Silent failure if logging fails
    }
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
    // Use 500 to signal retry to Meta if DB is down
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
    // Just exit if empty
    exit;
}

// Log Payload (truncated)
log_debug("Payload: " . substr($body, 0, 1500));

$payload = json_decode($body, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    log_debug("JSON Decode Error: " . json_last_error_msg());
    exit;
}

// Respond 200 OK immediately for WhatsApp
if (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
    http_response_code(200);

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

                // Explicitly log the ID we are looking for
                log_debug("Processing for Phone ID: [$phone_number_id]");

                // Robust Query: Trim and use prepared statement
                $stmt = $pdo->prepare("SELECT id, whatsapp_phone_number_id FROM users WHERE TRIM(whatsapp_phone_number_id) = ? LIMIT 1");
                $stmt->execute([trim((string)$phone_number_id)]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    log_debug("ERROR: No tenant found for Phone ID: [$phone_number_id]. Check 'users' table.");
                    continue;
                }

                $user_id = $user['id'];
                log_debug("Tenant Found: User ID $user_id");

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
                                 $msg_body = $message['interactive']['button_reply']['title'];
                             } elseif($type == 'list_reply') {
                                 $msg_body = $message['interactive']['list_reply']['title'];
                             } else {
                                 $msg_body = "[Interactive]";
                             }
                        } else {
                            $msg_body = "[" . ucfirst($msg_type) . "]";
                        }

                        // 1. Find/Create Contact
                        $normalized_phone = '+' . ltrim($wa_id, '+');

                        // Name logic
                        $contact_name = $normalized_phone;
                        foreach ($contacts_data as $c) {
                            if ($c['wa_id'] === $wa_id) {
                                $contact_name = $c['profile']['name'] ?? $normalized_phone;
                                break;
                            }
                        }

                        // Upsert Contact
                        $stmt_contact = $pdo->prepare("SELECT id FROM contacts WHERE phone_number = ? LIMIT 1");
                        $stmt_contact->execute([$normalized_phone]);
                        $contact = $stmt_contact->fetch(PDO::FETCH_ASSOC);

                        if ($contact) {
                            $contact_id = $contact['id'];
                        } else {
                            $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number, created_at) VALUES (?, ?, NOW())");
                            $stmt_new_contact->execute([$contact_name, $normalized_phone]);
                            $contact_id = $pdo->lastInsertId();
                            log_debug("Created Contact ID: $contact_id");
                        }

                        // 2. Find/Create Conversation
                        $stmt_conv = $pdo->prepare("SELECT id FROM conversations WHERE contact_id = ? LIMIT 1");
                        $stmt_conv->execute([$contact_id]);
                        $conversation = $stmt_conv->fetch(PDO::FETCH_ASSOC);

                        if ($conversation) {
                            $conversation_id = $conversation['id'];
                            $stmt_update = $pdo->prepare("UPDATE conversations SET updated_at = NOW(), last_message_preview = ? WHERE id = ?");
                            $stmt_update->execute([$msg_body, $conversation_id]);
                        } else {
                            $stmt_new_conv = $pdo->prepare("INSERT INTO conversations (contact_id, last_message_preview, status, updated_at, created_at) VALUES (?, ?, 'open', NOW(), NOW())");
                            $stmt_new_conv->execute([$contact_id, $msg_body]);
                            $conversation_id = $pdo->lastInsertId();
                            log_debug("Created Conversation ID: $conversation_id");
                        }

                        // 3. Insert Message
                        $stmt_msg = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, created_at, status) VALUES (?, 'contact', ?, NOW(), 'received')");
                        if ($stmt_msg->execute([$conversation_id, $msg_body])) {
                            log_debug("Message Saved! ID: " . $pdo->lastInsertId());
                        } else {
                            log_debug("Message Save Failed: " . implode(" ", $stmt_msg->errorInfo()));
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        log_debug("EXCEPTION: " . $e->getMessage());
    }

    exit;
}

// --- 6. FLUTTERWAVE LOGIC (Restored) ---
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

        // Fetch tenant secret
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
        // Default response for unknown payloads to keep healthy status
        http_response_code(200);
    }
} catch (Throwable $e) {
    log_debug("Legacy/Flutterwave Error: " . $e->getMessage());
    http_response_code(500);
}
?>
