<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$conversation_id = $data['conversation_id'] ?? null;
$status = $data['status'] ?? null; // 'open' or 'closed'

if (!$conversation_id || !in_array($status, ['open', 'closed'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

try {
    $stmt = $pdo->prepare("UPDATE conversations SET status = ? WHERE id = ?");
    $stmt->execute([$status, $conversation_id]);

    echo json_encode(['success' => true, 'message' => 'Status updated', 'new_status' => $status]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>