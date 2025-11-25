<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

$conversation_id = $input['conversation_id'] ?? null;
$field = $input['field'] ?? null;
$value = $input['value'] ?? null;

if (!$conversation_id || !$field) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

// Sanitize field name to prevent SQL injection
$allowed_fields = ['email', 'notes'];
if (!in_array($field, $allowed_fields)) {
    echo json_encode(['success' => false, 'message' => 'Invalid field specified.']);
    exit();
}

try {
    // Get contact_id from conversation_id
    $stmt = $pdo->prepare("SELECT contact_id FROM conversations WHERE id = ?");
    $stmt->execute([$conversation_id]);
    $contact_id = $stmt->fetchColumn();

    if (!$contact_id) {
        echo json_encode(['success' => false, 'message' => 'Contact not found for this conversation.']);
        exit();
    }

    // Update the contact's details
    $sql = "UPDATE contacts SET {$field} = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$value, $contact_id]);

    echo json_encode(['success' => true, 'message' => 'Contact updated successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>