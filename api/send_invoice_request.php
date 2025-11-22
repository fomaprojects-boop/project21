<?php
// ===== TUNAONGEZA HII ILI KULAZIMISHA MAKOSA YAONEKANE =====
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// =========================================================

// api/send_invoice_request.php
session_start();
header('Content-Type: application/json');

// Ingiza PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ingiza mafaili yetu ya "ubongo"
// Kama kosa liko hapa (k.m. faili halipo), sasa litaonekana
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php'; // Hakikisha hii ipo kabla ya mailer_config
require_once __DIR__ . '/mailer_config.php'; 

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
$user_id = $_SESSION['user_id']; 

$data = json_decode(file_get_contents('php://input'), true);
$vendor_id = $data['vendor_id'] ?? null;

if (empty($vendor_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Vendor ID is missing.']);
    exit();
}

$payout_request_id = null;
$vendor_email = '';
$vendor_name = '';
$request_token = '';

try {
    // Hakikisha $pdo ipo kabla ya kuitumia
    if (!isset($pdo) || !$pdo) {
        throw new Exception("Database connection failed ('pdo' variable is null). Check api/db.php credentials.");
    }
    
    $pdo->beginTransaction();

    // Hatua 1: Pata taarifa za Vendor
    $stmt = $pdo->prepare("SELECT full_name, email FROM vendors WHERE id = ?");
    $stmt->execute([$vendor_id]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        throw new Exception('Vendor not found.');
    }
    $vendor_email = $vendor['email'];
    $vendor_name = $vendor['full_name'];

    // Hatua 2: Tengeneza tokeni ya kipekee
    $request_token = bin2hex(random_bytes(32));

    // Hatua 3: Hifadhi ombi jipya kwenye database
    $stmt = $pdo->prepare(
        "INSERT INTO payout_requests (vendor_id, service_type, amount, payment_method, invoice_url, status, request_token) 
         VALUES (?, 'Not Submitted', 0.00, 'Not Submitted', 'Not Submitted', 'Pending', ?)"
    );
    $stmt->execute([$vendor_id, $request_token]);
    $payout_request_id = $pdo->lastInsertId();
    
    // Hatua 4: Kamilisha muamala wa database
    $pdo->commit();

} catch (Throwable $e) { // Inakamata Errors zote na Exceptions
    
    // Angalia kama $pdo ipo kabla ya kuitumia
    if (isset($pdo) && $pdo && $pdo->inTransaction()) { 
        $pdo->rollBack(); 
    }
    http_response_code(500);
    // Sasa itarudisha ujumbe halisi wa kosa
    echo json_encode(['status' => 'error', 'message' => 'PHP Error: ' . $e->getMessage()]);
    exit();
}

// --- Ikiwa DB imefanikiwa, sasa jaribu kutuma barua pepe ---
try {
    // Hatua 5: Tuma barua pepe kwa kutumia 'mailer_config.php'
    $mail = getMailerInstance($pdo, $user_id);
    
    // Wapokeaji
    $mail->addAddress($vendor_email, $vendor_name); 

    // Maudhui
    // (Hakikisha BASE_URL imekuwa defined kwenye config.php)
    $submission_link = rtrim(BASE_URL, '/') . "/submit_invoice.php?token=" . $request_token;
    
    $mail->isHTML(true);
    $mail->Subject = 'Action Required: Submit Your Invoice for Payment';
    
    // Improved Email Body
    $email_body = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Invoice Submission Request</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; }
            .header { text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 15px; margin-bottom: 20px; }
            .header img { max-width: 150px; }
            .content { padding: 0 15px; }
            .button { background-color: #4f46e5; color: white !important; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; }
            .footer { margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #888; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <img src='https://i.imgur.com/83wwt6F.png' alt='ChatMe Logo'>
                <h2>Invoice Submission Request</h2>
            </div>
            <div class='content'>
                <h3>Hello {$vendor_name},</h3>
                <p>We are initiating a payment for services/goods you recently provided. To ensure a prompt and secure payment, we need you to submit your invoice and payment details through our secure portal.</p>
                <p>Please click the button below to proceed:</p>
                <p style='text-align:center; margin: 25px 0;'>
                    <a href='{$submission_link}' class='button'>Submit Your Invoice</a>
                </p>
                <p>If you have any questions, please do not hesitate to contact our accounts department.</p>
                <p>Best regards,<br>The ChatMe Team</p>
            </div>
            <div class='footer'>
                <p>&copy; " . date('Y') . " ChatMe. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    $mail->Body = $email_body;
    $mail->AltBody = "Hello {$vendor_name},\n\nPlease use the following secure link to submit your invoice for payment:\n{$submission_link}\n\nThank you,\nThe ChatMe Team";

    $mail->send();
    
    echo json_encode(['status' => 'success', 'message' => "Invoice request has been sent to {$vendor_name}."]);

} catch (Exception $e) {
    // Hili ni kosa la KUTUMA EMAIL.
    echo json_encode([
        'status' => 'warning', 
        'message' => "Request created, but failed to send email: {$mail->ErrorInfo}. Please check your mail settings. The request is now 'Pending' in the Payouts tab."
    ]);
}
?>