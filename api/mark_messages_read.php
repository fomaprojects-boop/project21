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

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'Missing conversation ID']);
    exit();
}

try {
    // Update status of incoming messages to 'read'
    $stmt = $pdo->prepare("
        UPDATE messages
        SET status = 'read'
        WHERE conversation_id = ?
        AND sender_type = 'contact'
        AND status = 'received'
    ");

    $stmt->execute([$conversation_id]);

    echo json_encode(['success' => true, 'message' => 'Messages marked as read']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>