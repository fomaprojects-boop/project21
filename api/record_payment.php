<?php
// api/record_payment.php
session_start();
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php'; // Hii inaleta $pdo
require_once __DIR__ . '/mailer_config.php'; // Hii inaleta getMailerInstance()

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Pata tenant ID kutoka kwa mtumiaji aliyelogin
$tenantId = $_SESSION['user_id']; 

$data = json_decode(file_get_contents('php://input'), true);
$invoice_id = $data['invoice_id'] ?? null;
$amount = (float)($data['amount'] ?? 0);
$payment_date = $data['payment_date'] ?? date('Y-m-d');
$notes = trim($data['notes'] ?? '');

if (empty($invoice_id) || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invoice ID and a valid amount are required.']);
    exit();
}

// --- Financial Year-End Closing Guardrail ---
$financial_year_to_check = date('Y', strtotime($payment_date));
$stmt_year = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
$stmt_year->execute([$financial_year_to_check]);
$year_status = $stmt_year->fetch(PDO::FETCH_ASSOC);

if ($year_status && $year_status['is_closed']) {
    http_response_code(403); // Forbidden
    echo json_encode(['status' => 'error', 'message' => "Financial year {$financial_year_to_check} is closed. Payments cannot be recorded for this period."]);
    exit();
}
// --- End Guardrail ---

$new_balance_due = 0; 
$email_warning = null; 

// Variables kwa ajili ya email ya advertiser (kama itatumika)
$send_advertiser_email = false;
$advertiser_details_for_email = null;
$ad_title_for_email = null;
$new_start_date_for_email = null;
$new_end_date_for_email = null;

try {
    $pdo->beginTransaction();

    // 1. Pata taarifa za ankara
    $stmt = $pdo->prepare("SELECT total_amount, amount_paid, balance_due, status FROM invoices WHERE id = ? FOR UPDATE");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new Exception("Invoice not found.");
    }
    if ($invoice['status'] === 'Paid') {
        throw new Exception("This invoice has already been fully paid.");
    }
    if (round($amount, 2) > round($invoice['balance_due'], 2)) {
         throw new Exception("Payment amount (TZS " . $amount . ") cannot be greater than the balance due (TZS " . $invoice['balance_due'] . ").");
    }

    // 2. Hifadhi malipo
    $stmt_pay = $pdo->prepare("INSERT INTO invoice_payments (invoice_id, amount, payment_date, notes) VALUES (?, ?, ?, ?)");
    $stmt_pay->execute([$invoice_id, $amount, $payment_date, $notes]);

    // 3. Sasisha ankara
    $new_amount_paid = $invoice['amount_paid'] + $amount;
    $new_balance_due = $invoice['balance_due'] - $amount; 
    
    if (abs($new_balance_due) < 0.01) {
        $new_balance_due = 0.00;
    }
    
    $new_status = ($new_balance_due <= 0) ? 'Paid' : 'Partially Paid';

    $stmt_update = $pdo->prepare("UPDATE invoices SET amount_paid = ?, balance_due = ?, status = ? WHERE id = ?");
    $stmt_update->execute([$new_amount_paid, $new_balance_due, $new_status, $invoice_id]);
    
    // --- LOGIC YA ADVERTISER NA UPDATE YA AD (KUTUMIA TABLE SAHIHI 'ads') ---
    if ($new_status === 'Paid') {
        // Angalia kama hii invoice inahusiana na Ad kwenye table ya 'ads'
        $stmt_ad = $pdo->prepare(
            "SELECT ad.id, ad.advertiser_id, ad.title, ad.status, ad.campaign_type
             FROM ads ad
             WHERE ad.invoice_id = ?"
        );
        $stmt_ad->execute([$invoice_id]);
        $ad_data = $stmt_ad->fetch(PDO::FETCH_ASSOC);

        if ($ad_data) {
            // Ni Ad. Anzisha mchakato wa 'Processing' na update tarehe
            
            // Weka status mpya
            $new_ad_status = $ad_data['status']; // Default ni status ya sasa
            
            if ($ad_data['status'] == 'Queued for Upload' || $ad_data['status'] == 'Pending Payment') {
                // Kama ni 'Dedicated' ('Queued') inakwenda 'Processing'
                // Kama ni 'Manual' ('Pending Payment') inakwenda 'Active' (kulingana na Model yako)
                $new_ad_status = ($ad_data['campaign_type'] == 'Dedicated') ? 'Processing' : 'Active';
            }

            // Weka tarehe mpya (siku 30 kuanzia malipo)
            $new_start_date = $payment_date;
            $new_end_date = (new \DateTime($payment_date))->add(new \DateInterval('P30D'))->format('Y-m-d');
            
            // Update Ad kwenye database
            $stmt_update_ad = $pdo->prepare(
                "UPDATE ads SET status = ?, payment_status = 'Paid', start_date = ?, end_date = ? 
                 WHERE id = ?"
            );
            $stmt_update_ad->execute([$new_ad_status, $new_start_date, $new_end_date, $ad_data['id']]);

            // Andaa taarifa kwa ajili ya email ya advertiser (itakayotumwa nje ya transaction)
            // Tunatumia $tenantId kutoka kwenye SESSION
            $stmt_adv_details = $pdo->prepare("SELECT name, email FROM advertisers WHERE id = ? AND tenant_id = ?");
            $stmt_adv_details->execute([$ad_data['advertiser_id'], $tenantId]);
            $advertiser_details = $stmt_adv_details->fetch(PDO::FETCH_ASSOC);

            if ($advertiser_details && $new_ad_status == 'Processing') {
                // Tuma email ya 'Processing' KAMA status imekuwa 'Processing'
                $send_advertiser_email = true;
                $advertiser_details_for_email = $advertiser_details;
                $ad_title_for_email = $ad_data['title'];
                $new_start_date_for_email = $new_start_date;
                $new_end_date_for_email = $new_end_date;
            }
            // (Kama imekuwa 'Active' moja kwa moja (kwa Manual), cron job haihusiki, 
            // tunaweza kuongeza email ya 'Active' hapa baadaye kama unataka)
        }
    }
    
    // 4. Kamilisha Transaction
    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    // Tuma error halisi
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit(); // Hakikisha script inaishia hapa
}

// --- UTUMAJI WA EMAIL (NJE YA TRANSACTION) ---

// 1. Tuma email ya SHUKRANI (kwa Customer/Contact wa Invoice)
try {
    $stmt_details = $pdo->prepare(
        "SELECT
            i.invoice_number,
            cust.name AS customer_name,
            cust.email AS customer_email,
            cont.name AS contact_name,
            cont.email AS contact_email
        FROM invoices i
        JOIN customers cust ON i.customer_id = cust.id
        LEFT JOIN contacts cont ON i.contact_id = cont.id
        WHERE i.id = ?"
    );
    $stmt_details->execute([$invoice_id]);
    $details = $stmt_details->fetch(PDO::FETCH_ASSOC);

    if ($details) {
        $email_to_send = !empty($details['contact_email']) ? $details['contact_email'] : $details['customer_email'];
        $name_to_send  = !empty($details['contact_name']) ? $details['contact_name'] : $details['customer_name'];

        if (!empty($email_to_send)) {
            $stmt_settings = $pdo->query("SELECT smtp_from_name FROM settings WHERE id = 1");
            $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);

            $mail = getMailerInstance($pdo);
            $mail->addAddress($email_to_send, $name_to_send);
            $mail->isHTML(true);
            $mail->Subject = 'Payment Confirmation for Invoice ' . $details['invoice_number'];
            $mail->Body = "
                <p>Dear {$name_to_send},</p>
                <p>We have received a payment of TZS " . number_format($amount, 2) . " for invoice {$details['invoice_number']}.</p>
                <p><strong>New Balance Due:</strong> TZS " . number_format($new_balance_due, 2) . "</p>
                <p>Thank you for your business!</p>
                <p>Best regards,<br>{$settings['smtp_from_name']}</p>
            ";
            $mail->send();
        } else {
             $email_warning = "Payment recorded, but no customer/contact email found for invoice.";
        }
    }

} catch (Exception $e) {
    // Hii sio error kubwa, tuna-log tu
    $email_warning = "Payment recorded, but failed to send confirmation email: {$e->getMessage()}";
}


// 2. Tuma email ya "PROCESSING" (kwa Advertiser, KAMA inahusika)
if ($send_advertiser_email) {
    try {
        $stmt_settings_adv = $pdo->query("SELECT smtp_from_name FROM settings WHERE id = 1");
        $settings_adv = $stmt_settings_adv->fetch(PDO::FETCH_ASSOC);

        $mail_adv = getMailerInstance($pdo);
        $mail_adv->addAddress($advertiser_details_for_email['email'], $advertiser_details_for_email['name']);
        $mail_adv->isHTML(true);
        $mail_adv->Subject = 'Payment Confirmation and Campaign Processing: ' . $ad_title_for_email;
        
        $start_date_formatted = (new \DateTime($new_start_date_for_email))->format('F j, Y');
        $end_date_formatted = (new \DateTime($new_end_date_for_email))->format('F j, Y');

        $mail_adv->Body = "
            <p>Hello {$advertiser_details_for_email['name']},</p>
            <p>We are happy to confirm that we have received your payment for the campaign '<strong>{$ad_title_for_email}</strong>'.</p>
            <p>Your campaign status is now <strong>Processing</strong>. Our system is preparing your ad (e.g., video upload), and it will be activated shortly.</p>
            <p>It is scheduled to run from <strong>{$start_date_formatted}</strong> until <strong>{$end_date_formatted}</strong>.</p>
            <p>You will receive another email confirmation as soon as your campaign is officially <strong>Active</strong> and live.</p>
            <p>Once active, you will start receiving daily performance reports via this email.</p>
            <p>Thank you for choosing our services!</p>
            <p>Best regards,<br>{$settings_adv['smtp_from_name']}</p>
        ";
        $mail_adv->send();

    } catch (Exception $e) {
        $email_warning = ($email_warning) 
            ? $email_warning . " | Also failed to send 'Processing' email: " . $e->getMessage()
            : "Failed to send 'Processing' email: " . $e->getMessage();
    }
}


// --- JIBU LA MWISHO KWA CLIENT ---
if ($email_warning) {
    echo json_encode([
        'status' => 'warning',
        'message' => "Payment recorded successfully, but: {$email_warning}"
    ]);
} else {
    echo json_encode([
        'status' => 'success', 
        'message' => 'Payment recorded successfully!'
    ]);
}
?>