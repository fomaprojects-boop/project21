<?php
// api/update_template.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$id = $data['id'] ?? null;
$name = trim($data['name'] ?? '');
$header = trim($data['header'] ?? '');
$body = trim($data['body'] ?? '');
$footer = trim($data['footer'] ?? '');
$quick_replies_raw = trim($data['quick_replies'] ?? '');
$variables = $data['variables'] ?? [];


if (empty($id) || empty($name) || empty($body)) {
    echo json_encode(['status' => 'error', 'message' => 'Template ID, name, and body are required.']);
    exit();
}

// Prepare quick replies as a JSON string
$quick_replies_json = null;
if (!empty($quick_replies_raw)) {
    $replies_array = array_map('trim', explode(',', $quick_replies_raw));
    $quick_replies_json = json_encode($replies_array);
}

// Prepare variables as a JSON string
$variables_json = null;
if (!empty($variables) && is_array($variables)) {
    $variables_json = json_encode($variables);
}


try {
    $stmt = $pdo->prepare(
        "UPDATE message_templates SET 
            name = ?, 
            header = ?,
            body = ?, 
            footer = ?,
            quick_replies = ?,
            variables = ?
        WHERE id = ?"
    );
    
    $stmt->execute([
        $name,
        empty($header) ? null : $header,
        $body,
        empty($footer) ? null : $footer,
        $quick_replies_json,
        $variables_json,
        $id
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Template updated successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>