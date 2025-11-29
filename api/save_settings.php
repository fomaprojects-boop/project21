<?php
// api/save_settings.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';
$user_id = $_SESSION['user_id']; // Hii ni ID ya mteja aliye-login

// Initialize $data with POST data
$data = $_POST;

// --- [MABADILIKO YANAANZIA HAPA] ---

// 1. Tenganisha 'Columns' Kulingana na 'Table'
// Hizi ni za Mfumo Mzima (zitaenda 'settings' table)
$system_columns = [
    'business_name', 'business_email', 'business_address',
    'whatsapp_token', 'whatsapp_phone_id',
    'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username',
    'smtp_password', 'smtp_from_email', 'smtp_from_name',
    'smtp_choice', 'default_invoice_template',
    'business_stamp_url', 'default_currency'
];

// Hizi ni za Mteja (zitaenda 'users' table)
$user_columns = [
    'flw_public_key', 'flw_secret_key', 'flw_encryption_key',
    'flw_display_name', 'flw_test_mode', 'flw_active', 'flw_webhook_secret_hash',
    'corporate_tax_rate', 'tin_number', 'vrn_number', 'vfd_enabled', 'vfd_frequency', 'vfd_is_verified',
    'exchange_rate'
];

// --- Upload ya Picha (Hii inabaki kama ilivyo) ---
if (isset($_FILES['business_stamp']) && $_FILES['business_stamp']['error'] == UPLOAD_ERR_OK) {
    // ... (Code yako ya upload ya 'business_stamp' inabaki hapa) ...
    // ... (Hakikisha 'business_stamp_url' inaongezwa kwenye $data) ...
    if (move_uploaded_file($_FILES['business_stamp']['tmp_name'], $uploadFile)) {
        $data['business_stamp_url'] = '../uploads/' . $fileName; // Hakikisha path ni sahihi
    }
}
// ... (Logic yako yote ya ku-handle upload) ...


// 2. Andaa 'Queries' mbili tofauti
$system_sql_parts = [];
$system_params = [];
$user_sql_parts = [];
$user_params = [];

// Tenganisha data kulingana na kile kilichotumwa
foreach ($data as $key => $value) {

    // --- Logic maalum kwa fields (Hii inabaki kama ilivyokuwa) ---
    if ($key === 'smtp_password' || $key === 'flw_secret_key' || $key === 'flw_encryption_key') {
        if (empty($value)) {
            continue; // Ruka
        }
    }
    if ($key === 'smtp_port') {
        $value = ($value === '') ? null : (int)$value;
    }
    if ($key === 'flw_test_mode' || $key === 'flw_active' || $key === 'vfd_enabled') {
        $value = ($value === 'on') ? 1 : 0;
    }
    // ... (Unaweza kuongeza logic nyingine hapa) ...

    if ($key === 'corporate_tax_rate') {
        $value = ($value === '') ? null : $value;
    }

    // --- [MABADILIKO] Tenganisha data kwenye 'queries' mbili ---
    if (in_array($key, $system_columns)) {
        // Hii ni ya 'settings' table
        $system_sql_parts[] = "$key = ?";
        $system_params[] = $value;

    } elseif (in_array($key, $user_columns)) {
        // Hii ni ya 'users' table
        $user_sql_parts[] = "$key = ?";
        $user_params[] = $value;
    }
}

// Handle checkboxes za Flutterwave zisipotumwa
if (!isset($data['flw_test_mode'])) {
    $user_sql_parts[] = "flw_test_mode = ?";
    $user_params[] = 0;
}
if (!isset($data['flw_active'])) {
    $user_sql_parts[] = "flw_active = ?";
    $user_params[] = 0;
}

// Handle vfd_enabled checkbox
if (!isset($data['vfd_enabled'])) {
    $user_sql_parts[] = "vfd_enabled = ?";
    $user_params[] = 0;
}

// 3. Endesha 'Queries' ndani ya 'Transaction'
try {
    $pdo->beginTransaction(); // Anzisha transaction

    // Query ya kwanza: Update 'settings' table (kwa Mfumo Mzima)
    if (!empty($system_sql_parts)) {
        $sql_system = "UPDATE settings SET " . implode(', ', $system_sql_parts) . " WHERE id = 1";
        $stmt_system = $pdo->prepare($sql_system);
        $stmt_system->execute($system_params);
    }

    // Query ya pili: Update 'users' table (kwa Mteja Aliye-login)
    if (!empty($user_sql_parts)) {
        $sql_user = "UPDATE users SET " . implode(', ', $user_sql_parts) . " WHERE id = ?";

        // Ongeza ID ya mteja mwishoni mwa 'params'
        $user_params[] = $user_id;

        $stmt_user = $pdo->prepare($sql_user);
        $stmt_user->execute($user_params);
    }

    $pdo->commit(); // Kiri (commit) mabadiliko yote

    echo json_encode(['status' => 'success', 'message' => 'Settings saved successfully.']);

} catch (PDOException $e) {
    $pdo->rollBack(); // Futa mabadiliko kama kosa limetokea
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
