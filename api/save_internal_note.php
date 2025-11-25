<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

$conversation_id = $input['conversation_id'] ?? null;
$content = $input['content'] ?? null;

if (!$conversation_id || !$content) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Insert internal note
    $stmt = $pdo->prepare("
        INSERT INTO messages
        (conversation_id, user_id, sender_type, content, message_type, is_internal, status, created_at, sent_at)
        VALUES
        (:conversation_id, :user_id, 'agent', :content, 'note', 1, 'read', NOW(), NOW())
    ");

    $stmt->execute([
        ':conversation_id' => $conversation_id,
        ':user_id' => $_SESSION['user_id'],
        ':content' => $content
    ]);

    echo json_encode(['success' => true, 'message' => 'Note saved']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
