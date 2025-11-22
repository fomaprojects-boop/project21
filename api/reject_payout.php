<?php
// api/reject_payout.php
session_start();
header('Content-Type: application/json');

// Ingiza PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ingiza autoload.php kutoka Composer
require_once __DIR__ . '/../vendor/autoload.php';
// Ingiza Database
require_once __DIR__ . '/db.php';
// === MABADILIKO MUHIMU #1: Ingiza "Ubongo" wa Email ===
require_once __DIR__ . '/mailer_config.php';

// Hakikisha mtumiaji ameingia (Hapa ndipo palikuwa na syntax error)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Pata data iliyotumwa
$data = json_decode(file_get_contents('php://input'), true);
$payout_request_id = $data['id'] ?? null;
$rejection_reason = trim($data['reason'] ?? '');

// Hakiki data
if (empty($payout_request_id) || empty($rejection_reason)) {
    echo json_encode(['status' => 'error', 'message' => 'Payout ID and rejection reason are required.']);
    exit();
}

$vendor_email = '';
$vendor_name = '';
$vendor_id = null;
$new_request_token = '';

try {
    $pdo->beginTransaction();

    // Hatua 1: Pata taarifa za ombi la zamani (ili kupata vendor_id)
    $stmt = $pdo->prepare(
        "SELECT v.full_name, v.email, v.id AS vendor_id 
         FROM vendors v
         JOIN payout_requests pr ON v.id = pr.vendor_id
         WHERE pr.id = ? AND pr.status = 'Submitted'"
    );
    $stmt->execute([$payout_request_id]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        throw new Exception("Payout request not found or not in a 'Submitted' state.");
    }
    
    $vendor_email = $vendor['email'];
    $vendor_name = $vendor['full_name'];
    $vendor_id = $vendor['vendor_id'];

    // Hatua 2: Sasisha ombi la zamani liwe 'Rejected'
    // (Hii sasa inafanikiwa baada ya wewe kuruhusu 'request_token' kuwa NULL)
    $stmt_update = $pdo->prepare("UPDATE payout_requests SET status = 'Rejected', rejection_reason = ?, request_token = NULL WHERE id = ?");
    $stmt_update->execute([$rejection_reason, $payout_request_id]);


    // Hatua 3: Tengeneza tokeni mpya na ombi jipya ('Pending')
    $new_request_token = bin2hex(random_bytes(32));
    $stmt_new = $pdo->prepare(
        "INSERT INTO payout_requests (vendor_id, service_type, amount, payment_method, invoice_url, status, request_token) 
         VALUES (?, 'Not Submitted', 0.00, 'Not Submitted', 'Not Submitted', 'Pending', ?)"
    );
    $stmt_new->execute([$vendor_id, $new_request_token]);
    
    // Hatua 4: Kamilisha mabadiliko ya Database
    $pdo->commit();

} catch (Exception $e) {
    // Kama kuna kosa lolote la Database, rudisha nyuma
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}

// Hatua 5: Tuma barua pepe (nje ya transaction)
try {
    // === MABADILIKO MUHIMU #2: Tumia "Ubongo" wako ===
    // Hii function itasoma settings kutoka DB (Custom SMTP au Default)
    $mail = getMailerInstance($pdo); 

    // Wapokeaji
    $mail->addAddress($vendor_email, $vendor_name); 

    // Maudhui
    $submission_link = "http://app.chatme.co.tz/submit_invoice.php?token=" . $new_request_token;

    $mail->isHTML(true);
    $mail->Subject = 'Update on Your Invoice Submission - Action Required';
    $mail->Body    = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
            <h2>Hello {$vendor_name},</h2>
            <p>We have reviewed your recent invoice submission. Unfortunately, it has been rejected for the following reason:</p>
            <blockquote style='background: #f1f1f1; border-left: 5px solid #d9534f; padding: 15px; margin: 15px 0;'>
                <strong>Reason:</strong> " . htmlspecialchars($rejection_reason) . "
            </blockquote>
            <p>Please review the details, make the necessary corrections, and **resubmit your invoice using the new link below**:</p>
            <a href='{$submission_link}' style='background-color: #4f46e5; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block;'>Resubmit Your Invoice</a>
            <p>If you have any questions, please reply to this email.</p>
            <p>Best regards,<br>The ChatMe Team</p>
        </div>
    ";
    $mail->AltBody = "Hello {$vendor_name},\n\nYour invoice was rejected for the following reason: " . htmlspecialchars($rejection_reason) . "\nPlease resubmit using this new link:\n{$submission_link}";
    
    $mail->send();
    
    echo json_encode(['status' => 'success', 'message' => 'Payout request has been rejected and the vendor has been notified with a new submission link.']);

} catch (Exception $e) {
    // Hata kama email ikishindikana, DB imesharekebishwa.
    echo json_encode([
        'status' => 'warning', 
        'message' => "Request was rejected, but failed to send notification email: {$mail->ErrorInfo}."
    ]);
}
?>