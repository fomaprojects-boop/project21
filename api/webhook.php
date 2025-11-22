<?php
// api/webhook.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// For logging webhook requests
$log_file = __DIR__ . '/../webhook_log.txt';
file_put_contents($log_file, "Webhook received at: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

// --- FLUTTERWAVE WEBHOOK LOGIC ---
$headers = getallheaders();
$signature = $headers['Verif-Hash'] ?? null;

if ($signature) {
    $tenant_id = $_GET['tenant_id'] ?? null;
    if (!$tenant_id) {
        http_response_code(400);
        file_put_contents($log_file, "Bad Request: Tenant ID is missing.\n", FILE_APPEND);
        exit();
    }

    // Fetch the tenant-specific secret hash from the users table
    $stmt = $pdo->prepare("SELECT flw_webhook_secret_hash FROM users WHERE id = ?");
    $stmt->execute([$tenant_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $local_secret_hash = $user['flw_webhook_secret_hash'] ?? null;

    if (!$local_secret_hash || $signature !== $local_secret_hash) {
        http_response_code(401);
        file_put_contents($log_file, "Invalid signature for tenant {$tenant_id}.\n", FILE_APPEND);
        exit();
    }

    $body = file_get_contents('php://input');
    $event = json_decode($body, true);
    file_put_contents($log_file, "Payload: " . $body . "\n", FILE_APPEND);

    if ($event && isset($event['event']) && $event['event'] === 'charge.completed' && isset($event['data']['status']) && $event['data']['status'] === 'successful') {
        $transaction_ref = $event['data']['tx_ref'] ?? null;
        $amount_paid = $event['data']['amount'] ?? 0;

        if ($transaction_ref) {
            try {
                $pdo->beginTransaction();

                // 1. Fetch the invoice using tx_ref (invoice_number) and tenant_id for security
                $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ? AND tenant_id = ?");
                $stmt->execute([$transaction_ref, $tenant_id]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($invoice) {
                    $invoice_id = $invoice['id'];
                    // 2. Record the payment
                    $stmt_payment = $pdo->prepare(
                        "INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, notes, tenant_id)
                         VALUES (?, ?, ?, 'Flutterwave', ?, ?)"
                    );
                    $stmt_payment->execute([$invoice_id, $amount_paid, date('Y-m-d'), "TXN Ref: " . $transaction_ref, $tenant_id]);

                    // 3. Update invoice status
                    $new_amount_paid = $invoice['amount_paid'] + $amount_paid;
                    $total_amount = $invoice['total_amount'];
                    $new_status = ($new_amount_paid >= $total_amount) ? 'Paid' : 'Partially Paid';

                    $stmt_update = $pdo->prepare(
                        "UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ? AND tenant_id = ?"
                    );
                    $stmt_update->execute([$new_amount_paid, $new_status, $invoice_id, $tenant_id]);

                    $pdo->commit();
                    file_put_contents($log_file, "Invoice #{$transaction_ref} (ID: {$invoice_id}) for tenant {$tenant_id} updated successfully.\n", FILE_APPEND);
                    http_response_code(200);
                } else {
                    $pdo->rollBack();
                    file_put_contents($log_file, "Invoice with number (tx_ref) '{$transaction_ref}' not found for tenant {$tenant_id}.\n", FILE_APPEND);
                    http_response_code(404); // Not Found
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                file_put_contents($log_file, "Error processing webhook for tenant {$tenant_id}: " . $e->getMessage() . "\n", FILE_APPEND);
                http_response_code(500);
            }
        } else {
            file_put_contents($log_file, "Webhook received for tenant {$tenant_id} with no tx_ref.\n", FILE_APPEND);
            http_response_code(400); // Bad Request
        }
    }
} else {
    file_put_contents($log_file, "No signature found.\n", FILE_APPEND);
}

// You can add logic for other webhooks (e.g., WhatsApp) here if needed.
?>
