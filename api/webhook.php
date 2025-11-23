<?php
// api/webhook.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// For logging webhook requests
$log_file = __DIR__ . '/../webhook_log.txt';
$debug_log_file = __DIR__ . '/../webhook_debug.log';

// --- 1. WHATSAPP VERIFICATION (GET) ---
// Handles the webhook verification challenge from Meta
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['hub_mode']) && $_GET['hub_mode'] === 'subscribe') {
    $verify_token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($verify_token === WEBHOOK_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        file_put_contents($log_file, "WhatsApp Webhook Verified at: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        exit();
    } else {
        http_response_code(403);
        file_put_contents($log_file, "WhatsApp Webhook Verification Failed. Token mismatch at: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
        exit();
    }
}

// --- 2. INBOUND MESSAGES (POST) ---
$headers = getallheaders();
$body = file_get_contents('php://input');

// Log raw payload for debugging "offline" issues
file_put_contents($debug_log_file, "Received POST: " . date('Y-m-d H:i:s') . "\n" . $body . "\n----------------\n", FILE_APPEND);

// Determine if this is a Flutterwave or WhatsApp payload
$is_flutterwave = isset($headers['Verif-Hash']);
$payload = json_decode($body, true);

if ($is_flutterwave) {
    // --- FLUTTERWAVE LOGIC ---
    $signature = $headers['Verif-Hash'] ?? null;
    $tenant_id = $_GET['tenant_id'] ?? null;

    if (!$tenant_id) {
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
        // ... existing Flutterwave logic ...
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
        // Acknowledgement for other events
        http_response_code(200);
    }

} elseif (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
    // --- WHATSAPP LOGIC ---
    http_response_code(200); // Immediately acknowledge to Meta to prevent retries

    try {
        foreach ($payload['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['value']['messages'] ?? false) {
                    $metadata = $change['value']['metadata'];
                    $phone_number_id = $metadata['phone_number_id'];

                    // 1. Identify Tenant (User) using Phone Number ID
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE whatsapp_phone_number_id = ? LIMIT 1");
                    $stmt->execute([$phone_number_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$user) {
                        file_put_contents($log_file, "Error: Received message for unknown Phone ID: $phone_number_id\n", FILE_APPEND);
                        continue;
                    }
                    $user_id = $user['id'];

                    $messages = $change['value']['messages'];
                    $contacts = $change['value']['contacts'] ?? [];

                    foreach ($messages as $message) {
                        $from = $message['from']; // Sender phone number
                        $msg_body = '';
                        $msg_type = $message['type'];

                        if ($msg_type == 'text') {
                            $msg_body = $message['text']['body'];
                        } elseif ($msg_type == 'button') {
                            $msg_body = $message['button']['text'];
                        } else {
                            $msg_body = "[$msg_type message]";
                        }

                        // 2. Handle Contact (Check if exists, else create)
                        // We need the contact name from the payload if available
                        $contact_name = $from; // Default to number
                        foreach ($contacts as $c) {
                            if ($c['wa_id'] === $from) {
                                $contact_name = $c['profile']['name'] ?? $from;
                                break;
                            }
                        }

                        $stmt_contact = $pdo->prepare("SELECT id FROM contacts WHERE phone_number = ? AND user_id = ?"); // Assuming contacts are tenant-scoped or global? Usually global or linked. Let's assume global for now based on schema, but best to link to user if multi-tenant.
                        // Schema check: 'contacts' table usually has 'user_id' or 'tenant_id'. If not, it's shared.
                        // Assuming shared or simple schema for now based on 'get_contacts.php'.
                        // Let's check if we can filter by user_id? The schema implies contacts might be global.
                        // But for Tech Provider, they should be scoped.
                        // We'll look for the contact.
                        $stmt_contact = $pdo->prepare("SELECT id FROM contacts WHERE phone_number = ?");
                        $stmt_contact->execute(['+' . $from]); // Add + if missing? Meta sends without +, DB usually stores with +.
                        // Meta sends '2557...' (no +). Database usually has '+255...'.
                        // Let's normalize.
                        $normalized_from = '+' . $from;
                        $stmt_contact->execute([$normalized_from]);
                        $contact_exist = $stmt_contact->fetch(PDO::FETCH_ASSOC);

                        $contact_id = null;
                        if ($contact_exist) {
                            $contact_id = $contact_exist['id'];
                        } else {
                            $stmt_new_contact = $pdo->prepare("INSERT INTO contacts (name, phone_number) VALUES (?, ?)");
                            $stmt_new_contact->execute([$contact_name, $normalized_from]);
                            $contact_id = $pdo->lastInsertId();
                        }

                        // 3. Handle Conversation
                        $stmt_conv = $pdo->prepare("SELECT id FROM conversations WHERE contact_id = ?");
                        $stmt_conv->execute([$contact_id]);
                        $conv = $stmt_conv->fetch(PDO::FETCH_ASSOC);

                        $conversation_id = null;
                        if ($conv) {
                            $conversation_id = $conv['id'];
                            // Update timestamp and preview
                            $stmt_update_conv = $pdo->prepare("UPDATE conversations SET updated_at = NOW(), last_message_preview = ? WHERE id = ?");
                            $stmt_update_conv->execute([$msg_body, $conversation_id]);
                        } else {
                            $stmt_new_conv = $pdo->prepare("INSERT INTO conversations (contact_id, last_message_preview, updated_at) VALUES (?, ?, NOW())");
                            $stmt_new_conv->execute([$contact_id, $msg_body]);
                            $conversation_id = $pdo->lastInsertId();
                        }

                        // 4. Insert Message
                        $stmt_msg = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content, created_at) VALUES (?, 'contact', ?, NOW())");
                        $stmt_msg->execute([$conversation_id, $msg_body]);

                        file_put_contents($log_file, "Saved message from $normalized_from for Tenant $user_id\n", FILE_APPEND);
                    }
                }
            }
        }
    } catch (Exception $e) {
        file_put_contents($log_file, "Error processing WhatsApp webhook: " . $e->getMessage() . "\n", FILE_APPEND);
    }
} else {
    // Unknown payload
    file_put_contents($log_file, "Unknown Webhook received.\n", FILE_APPEND);
    http_response_code(400);
}
?>
