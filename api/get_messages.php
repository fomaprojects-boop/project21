<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Hauruhusiwi.']);
    exit;
}

require 'db.php';

// Hakikisha tumepokea ID ya mazungumzo
$conversationId = isset($_GET['conversation_id']) ? $_GET['conversation_id'] : (isset($_GET['id']) ? $_GET['id'] : null);

if (!$conversationId) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Conversation ID haipo.']);
    exit;
}

$conversationId = filter_var($conversationId, FILTER_VALIDATE_INT);

if ($conversationId === false) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Conversation ID si sahihi.']);
    exit;
}

try {
    // Pagination parameters
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = ($page - 1) * $limit;

    // Get messages with pagination (latest first, then reversed)
    $stmt = $pdo->prepare("
        SELECT id, sender_type, content, sent_at, created_at, status, is_internal, scheduled_at
        FROM messages
        WHERE conversation_id = :conversation_id
        ORDER BY sent_at DESC
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':conversation_id', $conversationId, PDO::PARAM_INT);
    $stmt->execute();

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Reverse to show chronological order (oldest at top)
    $messages = array_reverse($messages);

    echo json_encode(['success' => true, 'messages' => $messages]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Kosa la Database: ' . $e->getMessage()]);
}
?>