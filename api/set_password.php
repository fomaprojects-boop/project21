<?php
// api/set_password.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php'; 

$data = json_decode(file_get_contents('php://input'), true);

$token = $data['token'] ?? '';
$password = $data['password'] ?? '';

if (empty($token) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Token and password are required.']);
    exit();
}

if (strlen($password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters long.']);
    exit();
}

try {
    // === MABADILIKO HAPA: Tumeongeza 'email' na 'role' kwenye SELECT ===
    $stmt = $pdo->prepare(
        "SELECT id, full_name, email, role FROM users 
         WHERE registration_token = ? AND token_expiry > NOW() AND status = 'Pending'"
    );
    // === MWISHO WA MABADILIKO ===

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'This invitation is invalid or has expired.']);
        exit();
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT); 

    $updateStmt = $pdo->prepare(
        "UPDATE users SET 
            password = ?, 
            status = 'Active', 
            registration_token = NULL, 
            token_expiry = NULL 
         WHERE id = ?"
    );
    
    $updateStmt->execute([$hashedPassword, $user['id']]);

    // Sasa mistari hii ni salama kwa sababu 'email' na 'role' zipo
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email']; 
    $_SESSION['user_role'] = $user['role'];   

    // Hii sasa itatumwa kama JSON halali bila makosa yoyote ya PHP
    echo json_encode(['status' => 'success', 'message' => 'Password set successfully! Redirecting...']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>