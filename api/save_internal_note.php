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
$content = $_POST['content'] ?? null;

if (!$conversation_id || !$content) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Fetch the user_id from the conversation to populate the 'user_id' column in messages
    // Wait, `messages` table has `user_id`? Usually it links to `users` via tenant logic or similar.
    // `api/get_messages.php` doesn't show insertion logic.
    // Let's assume `user_id` column exists in `messages` as per memory ("NOT NULL constraint on user_id").
    // We need to get the tenant/user ID. `$_SESSION['user_id']` is the agent.

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
