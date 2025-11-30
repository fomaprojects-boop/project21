<?php
session_start();
header('Content-Type: application/json');
require 'db.php';
require 'helpers/PermissionHelper.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$tenantId = getCurrentTenantId();

// Verify user is Admin (Permission based)
if (!hasPermission($userId, 'manage_settings')) {
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$enable = isset($data['enable']) && $data['enable'] ? 1 : 0;

try {
    $stmt = $pdo->prepare("UPDATE tenants SET remote_access_enabled = ? WHERE id = ?");
    $stmt->execute([$enable, $tenantId]);

    // Generate new PIN if enabling
    if ($enable) {
        $newPin = strtoupper(substr(md5(uniqid()), 0, 6));
        $stmt = $pdo->prepare("UPDATE tenants SET support_pin = ? WHERE id = ?");
        $stmt->execute([$newPin, $tenantId]);
        echo json_encode(['status' => 'success', 'message' => 'Remote access enabled', 'pin' => $newPin]);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Remote access disabled']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
