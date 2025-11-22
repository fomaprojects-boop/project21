<?php
// api/delete_user.php
session_start();
header('Content-Type: application/json');

// Hakikisha mtumiaji ameingia na ana ruhusa
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$userIdToDelete = $data['id'] ?? null;

if (!$userIdToDelete) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'User ID is required.']);
    exit();
}

// Zuia mtumiaji kujifuta mwenyewe
if ($userIdToDelete == $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You cannot delete your own account.']);
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userIdToDelete]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'User deleted successfully.']);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found or already deleted.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
