<?php
session_start();
header('Content-Type: application/json');
require '../db.php';

if (!isset($_SESSION['super_admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$tenantId = $data['tenant_id'] ?? null;

if (!$tenantId) {
    echo json_encode(['status' => 'error', 'message' => 'Tenant ID required']);
    exit;
}

try {
    // 1. Verify Remote Access Permission
    $stmt = $pdo->prepare("SELECT id, business_name, remote_access_enabled FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        echo json_encode(['status' => 'error', 'message' => 'Tenant not found']);
        exit;
    }

    if ($tenant['remote_access_enabled'] != 1) {
        echo json_encode(['status' => 'error', 'message' => 'Remote access is disabled by this tenant.']);
        exit;
    }

    // 2. Find Admin User for this Tenant
    // We try to find a user with role 'Admin' for this tenant
    $userStmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, u.role_id
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.tenant_id = ? AND r.name = 'Admin'
        LIMIT 1
    ");
    $userStmt->execute([$tenantId]);
    $adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$adminUser) {
        // Fallback: Any user if no admin found (unlikely)
        $userStmt = $pdo->prepare("SELECT id, full_name, role, role_id FROM users WHERE tenant_id = ? LIMIT 1");
        $userStmt->execute([$tenantId]);
        $adminUser = $userStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$adminUser) {
        echo json_encode(['status' => 'error', 'message' => 'No users found for this tenant']);
        exit;
    }

    // 3. Set Session Context
    // We preserve super admin session but overlay tenant context
    $_SESSION['user_id'] = $adminUser['id'];
    $_SESSION['user_name'] = $adminUser['full_name'];
    $_SESSION['user_role'] = $adminUser['role'];
    $_SESSION['role_id'] = $adminUser['role_id'];
    $_SESSION['tenant_id'] = $tenantId;
    $_SESSION['is_impersonating'] = true;

    // Fetch Permissions
    $permStmt = $pdo->prepare("SELECT p.slug FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id = ?");
    $permStmt->execute([$adminUser['role_id']]);
    $_SESSION['permissions'] = $permStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['status' => 'success', 'message' => 'Logged in as tenant admin', 'redirect' => '../index.php']);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
