<?php
// api/test_smtp.php
session_start();
header('Content-Type: application/json');

// 1. Jumuisha vitu muhimu
require_once __DIR__ . '/../vendor/autoload.php'; // PHPMailer
require_once __DIR__ . '/db.php';                 // Database connection
require_once __DIR__ . '/config.php';              // Ili kupata default SMTP settings

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 2. Angalia kama mtumiaji ameingia
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}
$user_id = $_SESSION['user_id'];

// 3. Soma data iliyotumwa kutoka JavaScript
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid input data.']);
    exit();
}

$mail = new PHPMailer(true);
try {
    // --- Pata Email ya Mtumiaji (kwa ajili ya kutuma test) ---
    $stmt_user = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || empty($user['email'])) {
        throw new Exception("Could not find your user email address to send the test to.");
    }
    $test_to_email = $user['email'];
    $test_to_name = $user['full_name'];

    // --- Chagua Mipangilio: Default au Custom ---
    if ($data['smtp_choice'] === 'default') {
        // Tumia default settings kutoka config.php
        $mail->isSMTP();
        $mail->Host       = DEFAULT_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = DEFAULT_SMTP_USERNAME;
        $mail->Password   = DEFAULT_SMTP_PASSWORD;
        $mail->Port       = DEFAULT_SMTP_PORT;
        $mail->SMTPSecure = DEFAULT_SMTP_SECURE;
        
        $from_email = DEFAULT_FROM_EMAIL;
        $from_name  = DEFAULT_FROM_NAME;
        
        $mail->Subject = 'ChatMe Default SMTP Test Message';
        $mail->Body    = 'This is a test message for the <b>Default ChatMe Mail</b> configuration. If you received this, your settings are correct!';

    } else {
        // Tumia custom settings kutoka kwenye fomu
        $from_email = $data['from_email'] ?? null;
        $from_name = $data['from_name'] ?? null;

        if (empty($from_email) || empty($from_name)) {
            throw new Exception("'From Email' and 'From Name' are required to send a test.");
        }
        if (empty($data['host']) || empty($data['port']) || empty($data['username'])) {
            throw new Exception("Host, Port, and Username are required.");
        }

        $mail->isSMTP();
        $mail->Host       = $data['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $data['username'];
        $mail->Password   = $data['password'] ?? '';
        $mail->Port       = (int)$data['port'];

        if ($data['secure'] === 'none' || empty($data['secure'])) {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = $data['secure']; // 'tls' au 'ssl'
        }
        
        $mail->Subject = 'ChatMe Custom SMTP Test Message';
        $mail->Body    = 'This is a test message from your <b>Custom SMTP</b> configuration on ChatMe. If you received this, your settings are correct!';
    }
    
    // --- Tuma Email ---
    $mail->setFrom($from_email, $from_name);
    $mail->addAddress($test_to_email, $test_to_name); 
    $mail->addReplyTo($from_email, $from_name);
    $mail->isHTML(true);
    $mail->AltBody = 'This is a test message from your ChatMe SMTP configuration. If you received this, your settings are correct!';

    $mail->send();
    
    // --- Majibu ya Mafanikio ---
    echo json_encode(['status' => 'success', 'message' => "Connection successful! A test email has been sent to {$test_to_email}."]);

} catch (Exception $e) {
    // --- Majibu ya Kosa ---
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Test failed: ' . $mail->ErrorInfo]);
}
?>