<?php
// api/get_payment_status.php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$ref = $_GET['ref'] ?? null;

if (!$ref) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Reference required']);
    exit();
}

try {
    $stmt = $pdo->prepare(
        "SELECT status 
         FROM payout_requests 
         WHERE tracking_number = ?"
    );
    $stmt->execute([$ref]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result) {
        // Rudisha status iliyopo kwenye DB (k.m. "Approved" au "Deposited")
        echo json_encode(['status' => $result['status']]);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Not Found']);
    }

} catch (PDOException $e) {
    error_log("Get Status Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>