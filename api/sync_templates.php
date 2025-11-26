<?php
// api/sync_templates.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';
require_once 'config.php';

try {
    // Get WhatsApp credentials from settings for the current user/account
    $stmt = $pdo->prepare("SELECT whatsapp_business_account_id, whatsapp_access_token FROM settings WHERE id = 1"); // Assuming settings are stored with id=1
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings || empty($settings['whatsapp_business_account_id']) || empty($settings['whatsapp_access_token'])) {
        throw new Exception('WhatsApp Business Account ID or Access Token is not configured.');
    }

    $waba_id = $settings['whatsapp_business_account_id'];
    $access_token = $settings['whatsapp_access_token'];

    $url = "https://graph.facebook.com/v21.0/{$waba_id}/message_templates";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$access_token}"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        $error_response = json_decode($response, true);
        throw new Exception("Failed to fetch templates from Meta: " . ($error_response['error']['message'] ?? 'Unknown error'));
    }

    $meta_templates = json_decode($response, true)['data'];
    $updated_count = 0;

    foreach ($meta_templates as $meta_template) {
        $stmt = $pdo->prepare("UPDATE message_templates SET status = ? WHERE meta_template_name = ?");
        $stmt->execute([
            $meta_template['status'],
            $meta_template['name']
        ]);
        if ($stmt->rowCount() > 0) {
            $updated_count++;
        }
    }

    echo json_encode(['status' => 'success', 'message' => "Sync complete. {$updated_count} templates updated."]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Sync failed: ' . $e->getMessage()]);
}
?>
