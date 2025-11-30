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
$snooze_until = $input['snooze_until'] ?? null; // Datetime string

if (!$conversation_id || !$snooze_until) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

try {
    // Attempt to snooze
    $stmt = $pdo->prepare("UPDATE conversations SET snoozed_until = :snooze_until, status = 'snoozed' WHERE id = :id");
    $stmt->execute([
        ':snooze_until' => $snooze_until,
        ':id' => $conversation_id
    ]);

    echo json_encode(['success' => true, 'message' => 'Conversation snoozed']);

} catch (PDOException $e) {
    // Check for "Data truncated" error (Code 1265 or SQLSTATE 01000) indicating ENUM limitation
    if ($e->getCode() == '01000' || strpos($e->getMessage(), 'Data truncated') !== false || strpos($e->getMessage(), '1265') !== false) {
        try {
            // Attempt to fix the schema dynamically
            $pdo->exec("ALTER TABLE conversations MODIFY COLUMN status ENUM('open', 'closed', 'snoozed') DEFAULT 'open'");

            // Retry the update
            $stmt = $pdo->prepare("UPDATE conversations SET snoozed_until = :snooze_until, status = 'snoozed' WHERE id = :id");
            $stmt->execute([
                ':snooze_until' => $snooze_until,
                ':id' => $conversation_id
            ]);

            echo json_encode(['success' => true, 'message' => 'Conversation snoozed (Schema Updated)']);
        } catch (Exception $ex) {
            // If fixing fails, fallback to just setting snoozed_until without changing status
            // This ensures functionality persists even if schema is locked
            $stmt = $pdo->prepare("UPDATE conversations SET snoozed_until = :snooze_until WHERE id = :id");
            $stmt->execute([
                ':snooze_until' => $snooze_until,
                ':id' => $conversation_id
            ]);
            echo json_encode(['success' => true, 'message' => 'Conversation snoozed (Status unchanged due to schema lock)']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>
