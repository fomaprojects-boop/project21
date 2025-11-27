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
    // Assuming settings are stored with id=1, or linked to user. Using ID 1 based on existing code.
    $stmt = $pdo->prepare("SELECT whatsapp_business_account_id, whatsapp_access_token FROM settings WHERE id = 1");
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
    $inserted_count = 0;
    $current_user_id = $_SESSION['user_id'];

    // Get existing templates to check for updates vs inserts
    $existing_templates_stmt = $pdo->prepare("SELECT id, name FROM message_templates WHERE user_id = ?");
    $existing_templates_stmt->execute([$current_user_id]);
    $existing_templates = $existing_templates_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [name => id]

    foreach ($meta_templates as $meta_template) {
        $name = $meta_template['name'];
        $status = $meta_template['status'];
        $category = $meta_template['category'];
        $components = $meta_template['components'];

        // Parse components
        $body = '';
        $header = '';
        $footer = '';
        $quick_replies = [];
        $variables = [];

        foreach ($components as $component) {
            if ($component['type'] === 'BODY') {
                $body = $component['text'];
            } elseif ($component['type'] === 'HEADER' && $component['format'] === 'TEXT') {
                $header = $component['text'];
            } elseif ($component['type'] === 'FOOTER') {
                $footer = $component['text'];
            } elseif ($component['type'] === 'BUTTONS') {
                foreach ($component['buttons'] as $button) {
                    if ($button['type'] === 'QUICK_REPLY') {
                        $quick_replies[] = $button['text'];
                    }
                }
            }
        }

        // Extract variables from body
        if (preg_match_all('/{{(.*?)}}/', $body, $matches)) {
            $variables = array_unique($matches[1]); // Remove duplicates
        }

        $quick_replies_json = !empty($quick_replies) ? json_encode($quick_replies) : null;
        $variables_json = !empty($variables) ? json_encode(array_values($variables)) : null;

        // Check if template exists for this user (by name)
        // Note: Meta templates are unique by name per WABA, but local DB scope is user_id.
        // Assuming user_id maps to the WABA owner.

        $template_exists = false;
        // Check our fetched list (case-insensitive key check would be better but exact name match is standard for Meta)
        if (array_key_exists($name, $existing_templates)) {
             $template_exists = true;
        } else {
             // Double check DB in case list is stale or case differs
             $check_stmt = $pdo->prepare("SELECT id FROM message_templates WHERE name = ? AND user_id = ?");
             $check_stmt->execute([$name, $current_user_id]);
             if ($check_stmt->fetch()) {
                 $template_exists = true;
             }
        }

        if ($template_exists) {
            // Update existing template
            $stmt = $pdo->prepare("UPDATE message_templates SET status = ?, category = ?, body = ?, header = ?, footer = ?, quick_replies = ?, variables = ? WHERE name = ? AND user_id = ?");
            $stmt->execute([
                $status,
                $category,
                $body,
                $header,
                $footer,
                $quick_replies_json,
                $variables_json,
                $name,
                $current_user_id
            ]);
            if ($stmt->rowCount() > 0) {
                $updated_count++;
            }
        } else {
            // Insert new template
            $stmt = $pdo->prepare("INSERT INTO message_templates (user_id, name, category, body, header, footer, quick_replies, variables, status, language, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $current_user_id,
                $name,
                $category,
                $body,
                $header,
                $footer,
                $quick_replies_json,
                $variables_json,
                $status,
                $meta_template['language'] ?? 'en_US'
            ]);
            $inserted_count++;
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => "Sync complete. {$inserted_count} new templates added, {$updated_count} updated."
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Sync failed: ' . $e->getMessage()]);
}
?>
