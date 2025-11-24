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
    // Pata ujumbe wote wa mazungumzo haya
    $stmt = $pdo->prepare("
        SELECT sender_type, content, sent_at, status
        FROM messages
        WHERE conversation_id = ?
        ORDER BY sent_at ASC
    ");
    $stmt->execute([$conversationId]);

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'messages' => $messages]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Kosa la Database: ' . $e->getMessage()]);
}
?>