<?php
// api/approve_payout.php
session_start();
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ONGEZA HII KWA AJILI YA DEBUGGING KWA MUDA (FUTA BAADAYE)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer_config.php';

// HAKIKISHA HII FILE IPO NA PATH NI SAHIHI
require_once __DIR__ . '/payment_report_generator.php'; 

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id']; // Ongeza hii

$data = json_decode(file_get_contents('php://input'), true);
$payout_request_id = $data['id'] ?? null;

if (empty($payout_request_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Payout ID is missing.']);
    exit();
}

$payment_success = true;

if (!$payment_success) {
    // Hii hutumika ikiwa kulikuwa na actual payment gateway
    echo json_encode(['status' => 'error', 'message' => 'Actual payment failed. Please check your payment gateway.']);
    exit();
}

$details = null;
$pdf_url = '';
$pdf_path = '';
$email_body = '';
$settings = [];

try {
    $pdo->beginTransaction();

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];

    $stmt_settings = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt_settings->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        throw new Exception("System settings not found.");
    }
    
    $company_name = htmlspecialchars($settings['smtp_from_name'] ?? $settings['business_name'] ?? 'The Team');

    $stmt = $pdo->prepare(
        "SELECT v.full_name, v.email FROM vendors v JOIN payout_requests pr ON v.id = pr.vendor_id WHERE pr.id = ?"
    );
    $stmt->execute([$payout_request_id]);
    $details = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$details) {
        throw new Exception("Payout request not found.");
    }
    
    // Generate a unique reference number first
    $reference_number = 'TR-' . strtoupper(uniqid());

    // Hatua MPYA: Hakikisha folder lipo na linaweza kuandikwa
    $upload_dir = __DIR__ . '/../uploads/payment_notifications/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            throw new Exception("Failed to create upload directory: " . $upload_dir);
        }
    }
    
    // Generate PDF using the new reference number
    $pdf_filename = 'payment_notification_' . $payout_request_id . '_' . time() . '.pdf';
    $pdf_path = $upload_dir . $pdf_filename;
    
    // TUMIA FUNCTION HII: generatePaymentReportPDF($pdo, $payout_request_id, 'F', $pdf_path, $reference_number);
    // Hii function lazima iwe ndani ya payment_report_generator.php

    // REKEBISHO MUHIMU: Tunahitaji kusubiri PDF i-generate.
    // Kwa kuwa huna source code ya payment_report_generator.php, nitaacha hii kama ilivyo
    // lakini tukishapata kosa la 500, tutajua inatokea hapa.
    generatePaymentReportPDF($pdo, $payout_request_id, 'F', $pdf_path, $reference_number);

    $pdf_url = 'uploads/payment_notifications/' . $pdf_filename;

    // Update database with the same reference number
    $stmt_update = $pdo->prepare(
        "UPDATE payout_requests SET 
            status = 'Approved', 
            processed_at = CURRENT_TIMESTAMP, 
            transaction_reference = ?,
            tracking_number = ?,
            payment_notification_pdf_url = ?
          WHERE id = ?"
    );
    $stmt_update->execute([$reference_number, $reference_number, $pdf_url, $payout_request_id]);

    $tracking_link = $baseUrl . '/track_payment.php?ref=' . urlencode($reference_number);

    // Email body
    $email_body = "
    <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
        <h2>Hello {$details['full_name']},</h2>
        <p>Your payment request has been approved and processed.</p>
        <p>Please find the official Payment Report attached to this email for your records.</p>
        <p><strong>Tracking Number:</strong> {$reference_number}</p>
        <div style='margin: 20px 0;'>
            <a href='{$tracking_link}' style='background-color: #4f46e5; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                Track Payment Progress
            </a>
        </div>
        <p>Best regards,<br>{$company_name}</p>
    </div>";

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log("Approve Payout Error: " . $e->getMessage());
    // HAPA NDIO TUTAPATA KOSA LILILOSABABISHA 500
    echo json_encode(['status' => 'error', 'message' => 'Failed to approve payout: ' . $e->getMessage()]);
    exit();
}

try {
    // TUMIA $user_id HAPA (Umeirekebisha)
    $mail = getMailerInstance($pdo, $user_id); 
    $mail->addAddress($details['email'], $details['full_name']);    
    $mail->isHTML(true);
    $mail->Subject = "Your Payment from {$settings['business_name']} has been Processed (Ref: {$reference_number})";
    $mail->Body     = $email_body;
    $mail->addAttachment($pdf_path, $pdf_filename);
    $mail->send();
    
    echo json_encode(['status' => 'success', 'message' => 'Payout approved and notification sent to vendor.']);

} catch (Exception $e) {
    error_log("Payout Email Error: " . $e->getMessage());
    echo json_encode([
        'status' => 'warning',     
        'message' => "Payout Approved, but failed to send notification email: {$e->getMessage()}. Please check mail settings."
    ]);
}
?>