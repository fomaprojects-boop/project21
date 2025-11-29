<?php
// api/get_settings.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';
$user_id = $_SESSION['user_id'];

try {
    // 1. Pata Mipangilio Mikuu (General Settings)
    $stmt = $pdo->prepare("SELECT * FROM settings WHERE id = 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        // Hili ni tatizo kubwa kama 'settings' table haina row ya id=1
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'System settings not found.']);
        exit();
    }

    // 2. Pata Mipangilio ya Mtumiaji (User-specific Settings)
    $user_stmt = $pdo->prepare("SELECT flw_webhook_secret_hash, whatsapp_access_token, whatsapp_phone_number_id, whatsapp_business_account_id, whatsapp_status, corporate_tax_rate, tin_number, vrn_number, vfd_enabled, vfd_frequency, vfd_is_verified, exchange_rate FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $user_settings = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_settings) {
        // Unganisha mipangilio ya mtumiaji kwenye mipangilio mikuu
        $settings = array_merge($settings, $user_settings);
    }

    // 3. Ficha 'Keys' za Siri kabla ya kutuma (Security)
    unset($settings['smtp_password']);
    
    // Ficha Flutterwave keys
    if (!empty($settings['flw_secret_key'])) $settings['flw_secret_key'] = true;
    if (!empty($settings['flw_encryption_key'])) $settings['flw_encryption_key'] = true;
    if (!empty($settings['flw_webhook_secret_hash'])) $settings['flw_webhook_secret_hash'] = true;

    // Ficha WhatsApp token
    if (!empty($settings['whatsapp_access_token'])) {
        $settings['whatsapp_access_token'] = true; // Onyesha tu kama ipo, sio token yenyewe
    }

    // 4. Tuma mipangilio iliyounganishwa
    echo json_encode($settings);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>