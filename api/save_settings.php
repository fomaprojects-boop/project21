<?php
// api/save_settings.php
session_start();
header('Content-Type: application/json');

require_once 'db.php';
require_once 'helpers/PermissionHelper.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$tenant_id = getCurrentTenantId();

// 1. Check Permissions
if (!hasPermission($user_id, 'manage_settings')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: manage_settings permission required.']);
    exit();
}

$data = $_POST;

// 2. Define Allowed Columns for 'settings' table
// These are all now in one table.
$allowed_columns = [
    'business_name', 'business_email', 'business_address',
    'whatsapp_token', 'whatsapp_phone_id', 'whatsapp_business_account_id', // Renamed in migration
    'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username',
    'smtp_password', 'smtp_from_email', 'smtp_from_name',
    'smtp_choice', 'default_invoice_template',
    'business_stamp_url', 'default_currency',
    'flw_public_key', 'flw_secret_key', 'flw_encryption_key',
    'flw_display_name', 'flw_test_mode', 'flw_active', 'flw_webhook_secret_hash',
    'corporate_tax_rate', 'tin_number', 'vrn_number', 'vfd_enabled', 'vfd_frequency', 'vfd_is_verified',
    'exchange_rate'
];

// 3. Handle File Upload (Business Stamp)
if (isset($_FILES['business_stamp']) && $_FILES['business_stamp']['error'] == UPLOAD_ERR_OK) {
    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $fileExt = strtolower(pathinfo($_FILES['business_stamp']['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array($fileExt, $allowedExts)) {
        $fileName = 'stamp_' . $tenant_id . '_' . time() . '.' . $fileExt;
        $uploadFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['business_stamp']['tmp_name'], $uploadFile)) {
            $data['business_stamp_url'] = 'uploads/' . $fileName;
        }
    }
}

// 4. Prepare Update Query
$sql_parts = [];
$params = [];

foreach ($data as $key => $value) {
    if (!in_array($key, $allowed_columns)) {
        continue;
    }

    // Skip empty password fields (don't overwrite with empty string)
    if (($key === 'smtp_password' || $key === 'flw_secret_key' || $key === 'flw_encryption_key' || $key === 'flw_webhook_secret_hash' || $key === 'whatsapp_token') && empty($value)) {
        continue;
    }

    // Data Type Handling
    if ($key === 'smtp_port') $value = ($value === '') ? null : (int)$value;
    if ($key === 'flw_test_mode' || $key === 'flw_active' || $key === 'vfd_enabled') {
        $value = ($value === 'on' || $value === '1' || $value === true) ? 1 : 0;
    }
    if ($key === 'corporate_tax_rate' || $key === 'exchange_rate') {
        $value = ($value === '') ? null : $value;
    }

    $sql_parts[] = "$key = ?";
    $params[] = $value;
}

// Handle checkboxes if not present in POST (unchecked = 0)
// Only if we are saving that section? Ideally yes, but simplistic approach:
if (!isset($data['flw_test_mode']) && isset($data['flw_public_key'])) { // Heuristic: if editing FLW section
    $sql_parts[] = "flw_test_mode = 0";
}
if (!isset($data['flw_active']) && isset($data['flw_public_key'])) {
    $sql_parts[] = "flw_active = 0";
}
if (!isset($data['vfd_enabled']) && isset($data['tin_number'])) {
    $sql_parts[] = "vfd_enabled = 0";
}

if (empty($sql_parts)) {
    echo json_encode(['status' => 'success', 'message' => 'No changes to save.']);
    exit();
}

try {
    $sql = "UPDATE settings SET " . implode(', ', $sql_parts) . " WHERE tenant_id = ?";
    $params[] = $tenant_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
