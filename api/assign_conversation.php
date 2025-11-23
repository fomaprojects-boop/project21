<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$conversation_id = $data['conversation_id'] ?? null;
$assign_to = $data['assign_to'] ?? null; // User ID or 'auto'

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
    exit();
}

try {
    if ($assign_to === 'auto') {
        // Round Robin Logic
        // 1. Find 'Staff' users (or 'Agent')
        // 2. Count their 'open' conversations
        // 3. Pick the one with the least count

        $stmt = $pdo->prepare("
            SELECT u.id, COUNT(c.id) as active_chats
            FROM users u
            LEFT JOIN conversations c ON u.id = c.assigned_to AND c.status = 'open'
            WHERE u.role IN ('Staff', 'Agent', 'Admin', 'Accountant') -- Adjust roles as needed
            GROUP BY u.id
            ORDER BY active_chats ASC, u.last_login DESC
            LIMIT 1
        ");
        $stmt->execute();
        $agent = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($agent) {
            $assign_to = $agent['id'];
        } else {
            echo json_encode(['success' => false, 'message' => 'No agents available for assignment.']);
            exit();
        }
    }

    $stmt = $pdo->prepare("UPDATE conversations SET assigned_to = ? WHERE id = ?");
    $stmt->execute([$assign_to, $conversation_id]);

    // Get Assignee Name for UI update
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$assign_to]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'message' => 'Assigned successfully', 'assigned_to' => $assign_to, 'assignee_name' => $user['full_name']]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>