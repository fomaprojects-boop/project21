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
// Allow 'enable' or 'remote_access_enabled' for flexibility
$enable = isset($data['enable']) ? $data['enable'] : (isset($data['remote_access_enabled']) ? $data['remote_access_enabled'] : 0);
$enable = $enable ? 1 : 0;

try {
    $stmt = $pdo->prepare("UPDATE tenants SET remote_access_enabled = ? WHERE id = ?");
    $stmt->execute([$enable, $tenantId]);

    // Generate new PIN if enabling and one doesn't exist (or always regenerate? User said 'Show the Tenant's unique support_pin'. Usually static per tenant until reset. Let's keep existing logic: generate if enabling).
    // Actually, if we just toggle, we might want to keep the same PIN unless explicitly reset.
    // But previous logic was: generate if enabling. Let's stick to that to ensure a valid PIN exists.
    $pin = null;
    if ($enable) {
        // Check if PIN exists
        $stmt = $pdo->prepare("SELECT support_pin FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $currentPin = $stmt->fetchColumn();

        if (!$currentPin) {
            $pin = strtoupper(substr(md5(uniqid()), 0, 6)); // 6-char Alphanumeric
            $stmt = $pdo->prepare("UPDATE tenants SET support_pin = ? WHERE id = ?");
            $stmt->execute([$pin, $tenantId]);
        } else {
            $pin = $currentPin;
        }
        echo json_encode(['status' => 'success', 'message' => 'Remote access enabled', 'pin' => $pin]);
    } else {
        echo json_encode(['status' => 'success', 'message' => 'Remote access disabled']);
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
