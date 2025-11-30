<?php
session_start();
header('Content-Type: application/json');
require '../db.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['username']) || empty($data['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Username and password required.']);
    exit;
}

$username = $data['username'];
$password = $data['password'];

try {
    $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['super_admin_id'] = $admin['id'];
        $_SESSION['super_admin_user'] = $admin['username'];
        echo json_encode(['status' => 'success', 'message' => 'Login successful', 'redirect' => 'dashboard.php']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
