<?php
// api/delete_contact.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$contact_id = $data['id'] ?? null;

if (!$contact_id) {
    echo json_encode(['status' => 'error', 'message' => 'Contact ID is required.']);
    exit();
}

try {
    $stmt = $pdo->prepare("DELETE FROM contacts WHERE id = ?");
    $stmt->execute([$contact_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Contact deleted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Contact not found or already deleted.']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>