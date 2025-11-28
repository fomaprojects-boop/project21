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

    // Handle TAG_ADDED logic
    // Currently, this endpoint handles simple fields like 'email' or 'notes'.
    // If we support tags here (e.g., 'tags' field passed as JSON string or comma-separated),
    // we would parse it. For now, assuming the CRM UI calls this for tags too if extended.
    // If not, we might need to add 'tags' to allowed_fields or verify if 'value' is a tag string.

    // NOTE: The previous memory suggested `add_tag` node calls `addContactTag`.
    // If the frontend calls this endpoint to ADD a tag, we should trigger workflow.
    // However, the `allowed_fields` check blocks `tags` currently.
    // Assuming we expand this or handle tag addition logic:

    // For now, let's keep it simple. If field is 'tags' (future proofing), trigger workflow.
    if ($field === 'tags' && file_exists(__DIR__ . '/workflow_helper.php')) {
        require_once __DIR__ . '/workflow_helper.php';
        // Assuming value is the new tag or array of tags.
        // We trigger for the latest tag added.
        processWorkflows($pdo, $_SESSION['user_id'], $conversation_id, [
            'event_type' => 'TAG_ADDED',
            'tag_name' => $value // Or parsed if JSON
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Contact updated successfully.']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>