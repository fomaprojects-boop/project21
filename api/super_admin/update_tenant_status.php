<?php
session_start();
header('Content-Type: application/json');
require '../db.php';

if (!isset($_SESSION['super_admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$tenantId = $data['tenant_id'] ?? null;
$status = $data['status'] ?? null;

if (!$tenantId || !in_array($status, ['active', 'suspended'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE tenants SET subscription_status = ? WHERE id = ?");
    $stmt->execute([$status, $tenantId]);

    echo json_encode(['status' => 'success', 'message' => 'Tenant status updated']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
