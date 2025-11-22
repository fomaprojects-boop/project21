<?php
// api/add_template.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$name = trim($data['name'] ?? '');
$body = trim($data['body'] ?? '');
$header = trim($data['header'] ?? null);
$footer = trim($data['footer'] ?? null);
$quick_replies_raw = trim($data['quick_replies'] ?? '');
$variables_raw = $data['variables'] ?? [];

if (empty($name) || empty($body)) {
    echo json_encode(['status' => 'error', 'message' => 'Template name and body are required.']);
    exit();
}

// Andaa quick replies ziwe JSON string
$quick_replies = null;
if (!empty($quick_replies_raw)) {
    $replies_array = array_map('trim', explode(',', $quick_replies_raw));
    $quick_replies = json_encode($replies_array);
}

// Andaa variables ziwe JSON string
$variables = null;
if (!empty($variables_raw) && is_array($variables_raw)) {
    $variables = json_encode($variables_raw);
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO message_templates (name, body, header, footer, quick_replies, variables, status) 
         VALUES (?, ?, ?, ?, ?, ?, 'Pending')"
    );
    
    $stmt->execute([
        $name,
        $body,
        empty($header) ? null : $header,
        empty($footer) ? null : $footer,
        $quick_replies,
        $variables
    ]);

    echo json_encode(['status' => 'success', 'message' => "Template '{$name}' created successfully and is pending approval."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
