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

$contact_id = $input['contact_id'] ?? null;
$email = $input['email'] ?? null;
$notes = $input['notes'] ?? null;
$tags = $input['tags'] ?? null; // Expecting JSON array string or array

if (!$contact_id) {
    echo json_encode(['success' => false, 'message' => 'Contact ID required']);
    exit();
}

try {
    // Format tags as JSON if it's an array
    if (is_array($tags)) {
        $tags = json_encode($tags);
    }

    $sql = "UPDATE contacts SET email = :email, notes = :notes, tags = :tags WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':email' => $email,
        ':notes' => $notes,
        ':tags' => $tags,
        ':id' => $contact_id
    ]);

    echo json_encode(['success' => true, 'message' => 'Contact updated successfully']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
