<?php
session_start();
header('Content-Type: application/json');
require '../db.php';

if (!isset($_SESSION['super_admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    // Stats
    $stats = [];
    $stats['total_tenants'] = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $stats['active_tenants'] = $pdo->query("SELECT COUNT(*) FROM tenants WHERE subscription_status = 'active'")->fetchColumn();
    $stats['suspended_tenants'] = $stats['total_tenants'] - $stats['active_tenants'];

    // Tenant List
    $stmt = $pdo->query("SELECT id, business_name, subscription_status, remote_access_enabled, support_pin, created_at FROM tenants ORDER BY created_at DESC");
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate revenue (optional placeholder)
    $stats['total_revenue'] = 0; // Future: Sum from subscriptions table if exists

    echo json_encode([
        'status' => 'success',
        'stats' => $stats,
        'tenants' => $tenants
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
