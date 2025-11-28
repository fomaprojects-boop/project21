<?php
// api/get_facebook_credentials.php

require_once 'db.php';

function getFacebookCredentials($userId) {
    global $pdo;

    // 1. Try User Settings (Tenant specific)
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id, whatsapp_business_account_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userSettings && !empty($userSettings['whatsapp_access_token']) && !empty($userSettings['whatsapp_phone_number_id'])) {
        return [
            'access_token' => $userSettings['whatsapp_access_token'],
            'phone_number_id' => $userSettings['whatsapp_phone_number_id'],
            'waba_id' => $userSettings['whatsapp_business_account_id'] ?? null
        ];
    }

    // 2. Fallback to Global Settings (System-wide)
    try {
        $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id, whatsapp_business_account_id FROM settings LIMIT 1");
        $stmt->execute();
        $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($globalSettings && !empty($globalSettings['whatsapp_access_token'])) {
            return [
                'access_token' => $globalSettings['whatsapp_access_token'],
                'phone_number_id' => $globalSettings['whatsapp_phone_number_id'],
                'waba_id' => $globalSettings['whatsapp_business_account_id'] ?? null
            ];
        }
    } catch (PDOException $e) {
        // Table might not exist yet or other DB error
        error_log("Global settings fetch failed: " . $e->getMessage());
    }

    // 3. Fail if nothing found
    // You might want to return null or throw an exception depending on usage
    return null;
}
?>
