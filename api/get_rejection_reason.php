<?php
// api/get_rejection_reason.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$payout_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payout_id === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Payout ID.']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT rejection_reason FROM payout_requests WHERE id = ?");
    $stmt->execute([$payout_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        echo json_encode(['status' => 'success', 'reason' => $result['rejection_reason']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Payout not found.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
