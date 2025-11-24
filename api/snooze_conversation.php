<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conversation_id = $_POST['conversation_id'] ?? null;
$snooze_until = $_POST['snooze_until'] ?? null; // Datetime string

if (!$conversation_id || !$snooze_until) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    $stmt = $pdo->prepare("UPDATE conversations SET snoozed_until = :snooze_until, status = 'snoozed' WHERE id = :id");
    $stmt->execute([
        ':snooze_until' => $snooze_until,
        ':id' => $conversation_id
    ]);

    echo json_encode(['success' => true, 'message' => 'Conversation snoozed']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
