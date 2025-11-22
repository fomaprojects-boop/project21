<?php
// api/delete_template.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$template_id = $data['id'] ?? null;

if (empty($template_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Template ID is missing.']);
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM message_templates WHERE id = ?");
    $stmt->execute([$template_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Template deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Template not found or could not be deleted.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
