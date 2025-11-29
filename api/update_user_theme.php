<?php
// api/update_user_theme.php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$theme = $data['theme'] ?? 'light';

if (!in_array($theme, ['light', 'dark'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid theme']);
    exit();
}

try {
    $stmt = $pdo->prepare("UPDATE users SET theme = ? WHERE id = ?");
    $stmt->execute([$theme, $user_id]);

    $_SESSION['theme'] = $theme; // Update session as well

    echo json_encode(['status' => 'success', 'message' => 'Theme updated']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>