<?php
// api/send_invitation.php
session_start();
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer_config.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/PermissionHelper.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$tenant_id = getCurrentTenantId();

// Verify Permission
if (!hasPermission($user_id, 'manage_users')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: manage_users permission required.']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$roleId = isset($data['role_id']) ? (int)$data['role_id'] : 0;

if (empty($name) || empty($email) || empty($roleId)) {
    echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit();
}

// Check if Role exists and belongs to this Tenant
$stmtRole = $pdo->prepare("SELECT name FROM roles WHERE id = ? AND tenant_id = ?");
$stmtRole->execute([$roleId, $tenant_id]);
$roleName = $stmtRole->fetchColumn();

if (!$roleName) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid role selected.']);
    exit();
}

$token = '';

try {
    // 1. Check uniqueness (Globally or Per Tenant? Usually per tenant, but email login is global unique key)
    $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt_check->execute([$email]);
    if ($stmt_check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Email address is already in use.']);
        exit();
    }

    $pdo->beginTransaction();

    // 2. Token
    $token = bin2hex(random_bytes(32));
    $token_expiry = date('Y-m-d H:i:s', time() + 86400);

    // 3. Insert User
    // Note: We populate both 'role' (legacy string) and 'role_id'
    $stmt = $pdo->prepare(
        "INSERT INTO users (tenant_id, full_name, email, role, role_id, status, registration_token, token_expiry)
         VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?)"
    );
    $stmt->execute([$tenant_id, $name, $email, $roleName, $roleId, $token, $token_expiry]);
    $newUserId = $pdo->lastInsertId();

    // Note: Default templates logic removed as it should be handled via migration or on first login/setup
    // But if critical for legacy flow, we can adapt it. Since we are moving to shared templates or SaaS templates,
    // copying templates per user is inefficient. We'll skip it for now unless requested.
    
    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    error_log("Database error during user creation: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit();
}

// 4. Send Email
try {
    $mail = getMailerInstance($pdo);
    
    $mail->addAddress($email, $name); 

    $registration_link = BASE_URL . "/accept_invitation.php?token=" . $token;
    
    $mail->isHTML(true);
    $mail->Subject = 'You are invited to join ChatMe';
    $mail->Body    = "
        <div style='font-family: Arial, sans-serif; line-height: 1.6;'>
            <h2>Hello {$name},</h2>
            <p>You have been invited to join the ChatMe platform as a <strong>{$roleName}</strong>.</p>
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
    echo json_encode([
        'status' => 'warning', 
        'message' => "User created, but email failed: {$mail->ErrorInfo}"
    ]);
}
?>
