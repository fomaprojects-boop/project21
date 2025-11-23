<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$status_filter = $_GET['status'] ?? 'all'; // 'all', 'open', 'closed'
$assigned_filter = $_GET['assigned_to'] ?? 'all'; // 'me', 'unassigned', 'all'
$search = $_GET['search'] ?? '';

try {
    $sql = "
        SELECT
            c.id as conversation_id,
            c.contact_id,
            c.last_message_preview,
            c.updated_at,
            c.status,
            c.assigned_to,
            con.name as contact_name,
            con.phone_number,
            u.full_name as assignee_name,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_type = 'contact' AND m.is_read = 0) as unread_count
        FROM conversations c
        JOIN contacts con ON c.contact_id = con.id
        LEFT JOIN users u ON c.assigned_to = u.id
        WHERE 1=1
    ";

    $params = [];

    if ($status_filter !== 'all') {
        $sql .= " AND c.status = ?";
        $params[] = $status_filter;
    }

    if ($assigned_filter === 'me') {
        $sql .= " AND c.assigned_to = ?";
        $params[] = $_SESSION['user_id'];
    } elseif ($assigned_filter === 'unassigned') {
        $sql .= " AND c.assigned_to IS NULL";
    }

    if (!empty($search)) {
        $sql .= " AND (con.name LIKE ? OR con.phone_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY c.updated_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'conversations' => $conversations]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>