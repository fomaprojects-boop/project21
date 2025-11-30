<?php
// api/get_settings.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';
require_once 'helpers/PermissionHelper.php';

$user_id = $_SESSION['user_id'];
$tenant_id = getCurrentTenantId();

try {
    // 1. Fetch Tenant Settings
    // We strictly fetch settings for the current tenant.
    $stmt = $pdo->prepare("SELECT * FROM settings WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        // Fallback: If no settings row exists for this tenant, verify if tenant exists and create default row?
        // Or return empty/default structure.
        // For now, if migration worked, this should exist.
        // If not, we might return error or defaults.
        $settings = [];
    }

    // 2. Add calculated/dynamic fields (e.g., Free Tier Check) if needed
    // $settings['free_tier_limit_reached'] = ... (Future SaaS logic)

    // 3. Security: Mask Secret Keys
    unset($settings['smtp_password']);

    // Mask Flutterwave keys if they exist
    if (!empty($settings['flw_secret_key'])) $settings['flw_secret_key'] = true;
    if (!empty($settings['flw_encryption_key'])) $settings['flw_encryption_key'] = true;
    if (!empty($settings['flw_webhook_secret_hash'])) $settings['flw_webhook_secret_hash'] = true;

    // Mask WhatsApp token
    if (!empty($settings['whatsapp_token'])) { // Note: Column name changed in migration from 'whatsapp_access_token'
        $settings['whatsapp_token'] = true;
    }
    // Also check legacy name just in case
    if (!empty($settings['whatsapp_access_token'])) {
        $settings['whatsapp_access_token'] = true;
    }

    // 4. Return Settings
    echo json_encode($settings);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
