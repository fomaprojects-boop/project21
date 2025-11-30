<?php
session_start();
header('Content-Type: application/json');
require '../../db.php';

if (!isset($_SESSION['super_admin_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$pin = $data['pin'] ?? null;

if (!$pin) {
    echo json_encode(['status' => 'error', 'message' => 'PIN is required']);
    exit;
}

try {
    // Fetch Tenant by PIN
    $stmt = $pdo->prepare("
        SELECT id, business_name, subscription_status, remote_access_enabled, support_pin
        FROM tenants
        WHERE support_pin = ?
        LIMIT 1
    ");
    $stmt->execute([$pin]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid PIN. Tenant not found.']);
        exit;
    }

    // Fetch Owner Email (Assuming role 'Admin' or first user)
    $ownerStmt = $pdo->prepare("
        SELECT email
        FROM users
        WHERE tenant_id = ?
        ORDER BY role = 'Admin' DESC, id ASC
        LIMIT 1
    ");
    $ownerStmt->execute([$tenant['id']]);
    $ownerEmail = $ownerStmt->fetchColumn();

    // Fetch Invoice Summary
    $invStmt = $pdo->prepare("
        SELECT
            SUM(total_amount) as total_billed,
            SUM(amount_paid) as total_paid
        FROM invoices
        WHERE tenant_id = ? AND status != 'Draft' AND status != 'Cancelled'
    ");
    $invStmt->execute([$tenant['id']]);
    $invoiceStats = $invStmt->fetch(PDO::FETCH_ASSOC);

    $totalPaid = $invoiceStats['total_paid'] ?? 0;
    $totalDue = ($invoiceStats['total_billed'] ?? 0) - $totalPaid;

    echo json_encode([
        'status' => 'success',
        'tenant' => [
            'id' => $tenant['id'],
            'business_name' => $tenant['business_name'],
            'owner_email' => $ownerEmail,
            'subscription_status' => $tenant['subscription_status'],
            'remote_access_enabled' => $tenant['remote_access_enabled'],
            'invoice_summary' => [
                'paid' => $totalPaid,
                'due' => $totalDue
            ]
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
