<?php
// api/get_conversations.php

header('Content-Type: application/json');
require_once 'db.php';

try {
    $stmt = $pdo->query("
        SELECT
            conv.id as conversation_id,
            cont.name as contact_name,
            cont.phone_number,
            conv.last_message_preview,
            conv.updated_at
        FROM conversations conv
        JOIN contacts cont ON conv.contact_id = cont.id
        ORDER BY conv.updated_at DESC
    ");

    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'conversations' => $conversations]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch conversations: ' . $e->getMessage()]);
}
