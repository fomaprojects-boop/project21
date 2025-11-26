<?php
// api/send_invitation.php
session_start();
header('Content-Type: application/json');

// Ingiza PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ingiza mafaili yetu ya "ubongo"
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer_config.php'; // HILI NI MUHIMU - Tunatumia usanidi mkuu
require_once __DIR__ . '/config.php'; // Ongeza config file
require_once __DIR__ . '/submit_default_templates.php'; // Ongeza file jipya

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$role = trim($data['role'] ?? '');

if (empty($name) || empty($email) || empty($role)) {
    echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit();
}

$token = ''; // Tunaitangaza hapa ili ijulikane kote

try {
    // Hatua 1: Angalia kwanza kama email tayari inatumika
    $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt_check->execute([$email]);
    if ($stmt_check->fetch()) {
        // Hili ni kosa la kawaida, sio la server. Tuma ujumbe sahihi.
        echo json_encode(['status' => 'error', 'message' => 'Email address is already in use.']);
        exit();
    }

    $pdo->beginTransaction();

    // Hatua 2: Tengeneza tokeni ya usajili
    $token = bin2hex(random_bytes(32));
    $token_expiry = date('Y-m-d H:i:s', time() + 86400); // Tokeni itaisha baada ya masaa 24

    // Hatua 3: Hifadhi mtumiaji mpya akiwa na status 'Pending'
    $stmt = $pdo->prepare(
        "INSERT INTO users (full_name, email, role, status, registration_token, token_expiry) 
         VALUES (?, ?, ?, 'Pending', ?, ?)"
    );
    $stmt->execute([$name, $email, $role, $token, $token_expiry]);
    $userId = $pdo->lastInsertId(); // Get the new user's ID

    // Add default templates for the new user
    $defaultTemplates = [
        [
            'name' => 'sample_marketing',
            'category' => 'MARKETING',
            'body' => 'Hi {{customer_name}}! Don\'t miss out on our special offer. Get 20% off on all new arrivals this week. Use code: PROMO20',
            'quick_replies' => 'Shop Now,Learn More'
        ],
        [
            'name' => 'sample_transactional',
            'category' => 'TRANSACTIONAL',
            'body' => 'Your order {{order_number}} has been shipped and is expected to arrive by {{delivery_date}}. Thank you for your purchase!',
            'quick_replies' => 'Track Order'
        ],
        [
            'name' => 'sample_otp',
            'category' => 'TRANSACTIONAL',
            'body' => 'Your verification code is {{otp_code}}. This code is valid for 10 minutes.',
            'quick_replies' => ''
        ]
    ];

    $stmt_template = $pdo->prepare(
        "INSERT INTO message_templates (user_id, name, category, body, status, quick_replies)
         VALUES (?, ?, ?, ?, 'PENDING', ?)"
    );

    foreach ($defaultTemplates as $template) {
        $stmt_template->execute([
            $userId,
            $template['name'],
            $template['category'],
            $template['body'],
            $template['quick_replies']
        ]);
    }
    
    $pdo->commit();

    // Submit default templates to Meta. This runs in the background.
    submit_all_default_templates_for_user($userId);

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    // We will just log the error and the final response will be sent after email attempt
    error_log("Database error during user creation: " . $e->getMessage());
    // No echo here, let it fall through to the email part
}

// --- Tuma barua pepe NJE ya transaction ---
try {
    // Hatua 4: Tuma barua pepe kwa kutumia 'mailer_config.php'
    $mail = getMailerInstance($pdo); // HII NDIYO MABADILIKO MAKUBWA
    
    // Wapokeaji
    $mail->addAddress($email, $name); 

    // Maudhui
    $registration_link = BASE_URL . "/accept_invitation.php?token=" . $token;
    
    $mail->isHTML(true);
    $mail->Subject = 'You are invited to join ChatMe';
    $mail->Body    = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
            <h2>Hello {$name},</h2>
            <p>You have been invited to join the ChatMe platform as an {$role}.</p>
            <p>To complete your registration and set your password, please click the link below:</p>
            <a href='{$registration_link}' style='background-color: #4f46e5; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block;'>Complete Registration</a>
            <p style='font-size: 12px; color: #777;'>This link will expire in 24 hours.</p>
            <p>Best regards,<br>The ChatMe Team</p>
        </div>
    ";
    $mail->AltBody = "Hello {$name},\n\nYou have been invited to join ChatMe. Complete your registration here: {$registration_link}";

    $mail->send();
    
    echo json_encode(['status' => 'success', 'message' => "Invitation sent successfully to {$email}."]);

} catch (Exception $e) {
    // Hili ni kosa la KUTUMA EMAIL.
    echo json_encode([
        'status' => 'warning', 
        'message' => "User was created, but failed to send invitation email: {$mail->ErrorInfo}. Please check your mail settings."
    ]);
}
?>