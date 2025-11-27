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
    $current_user_id = $_SESSION['user_id'];
    $waba_id = null;
    $access_token = null;

    // 1. Try User Credentials (Tenant/User specific)
    $user_stmt = $pdo->prepare("SELECT whatsapp_business_account_id, whatsapp_access_token FROM users WHERE id = ?");
    $user_stmt->execute([$current_user_id]);
    $user_creds = $user_stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_creds && !empty($user_creds['whatsapp_business_account_id']) && !empty($user_creds['whatsapp_access_token'])) {
        $waba_id = $user_creds['whatsapp_business_account_id'];
        $access_token = $user_creds['whatsapp_access_token'];
    } else {
        // 2. Fallback to Global Settings
        $settings_stmt = $pdo->prepare("SELECT whatsapp_business_account_id, whatsapp_access_token FROM settings WHERE id = 1");
        $settings_stmt->execute();
        $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

        if ($settings && !empty($settings['whatsapp_business_account_id']) && !empty($settings['whatsapp_access_token'])) {
            $waba_id = $settings['whatsapp_business_account_id'];
            $access_token = $settings['whatsapp_access_token'];
        }
    }

    if (empty($waba_id) || empty($access_token)) {
        // Return auth_error to prompt user to Settings
        echo json_encode([
            'status' => 'auth_error',
            'message' => 'WhatsApp account is not connected. Please go to Settings > Channels.'
        ]);
        exit;
    }

    $url = "https://graph.facebook.com/v21.0/{$waba_id}/message_templates?limit=250";

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
        $meta_error = $error_response['error'] ?? [];
        $meta_code = $meta_error['code'] ?? 0;
        $meta_message = $meta_error['message'] ?? 'Unknown error';

        if ($meta_code == 190) {
            echo json_encode([
                'status' => 'auth_error',
                'message' => 'Your WhatsApp connection has expired. Please reconnect in Settings > Channels.'
            ]);
            exit;
        }

        throw new Exception("Meta API Error: " . $meta_message);
    }

    $data = json_decode($response, true);
    $meta_templates = $data['data'] ?? [];

    $updated_count = 0;
    $inserted_count = 0;

    // Get existing templates to check for updates vs inserts
    $existing_templates_stmt = $pdo->prepare("SELECT id, name FROM message_templates WHERE user_id = ?");
    $existing_templates_stmt->execute([$current_user_id]);
    $existing_templates = $existing_templates_stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [name => id]

    foreach ($meta_templates as $meta_template) {
        $name = $meta_template['name'];
        $status = $meta_template['status']; // APPROVED, REJECTED, PENDING, etc.
        $category = $meta_template['category'];
        $components = $meta_template['components'];

        // Parse components
        $body = '';
        $header = '';
        $footer = '';
        $quick_replies = [];
        $variables = [];

        // New Fields for Complex Templates
        $header_type = 'TEXT'; // Default
        $buttons_data = []; // Store full structure for URL/Phone buttons

        foreach ($components as $component) {
            if ($component['type'] === 'BODY') {
                $body = $component['text'];
                // Extract variables from body
                if (preg_match_all('/{{(.*?)}}/', $body, $matches)) {
                    $variables = array_unique($matches[1]);
                }
            } elseif ($component['type'] === 'HEADER') {
                $header_type = $component['format']; // TEXT, IMAGE, VIDEO, DOCUMENT
                if ($header_type === 'TEXT') {
                    $header = $component['text'];
                }
                // For MEDIA headers, we don't store text, just the type is important for UI to show file picker
            } elseif ($component['type'] === 'FOOTER') {
                $footer = $component['text'];
            } elseif ($component['type'] === 'BUTTONS') {
                foreach ($component['buttons'] as $button) {
                    if ($button['type'] === 'QUICK_REPLY') {
                        $quick_replies[] = $button['text'];
                    }
                    // Store full button config for all types (QUICK_REPLY, URL, PHONE_NUMBER)
                    // URL buttons specifically might have dynamic variables {{1}} in the url field
                    $buttons_data[] = $button;
                }
            }
        }

        $quick_replies_json = !empty($quick_replies) ? json_encode($quick_replies) : null;
        $variables_json = !empty($variables) ? json_encode(array_values($variables)) : null;
        $buttons_data_json = !empty($buttons_data) ? json_encode($buttons_data) : null;

        // Determine if we update or insert
        $template_id = null;

        if (isset($existing_templates[$name])) {
            $template_id = $existing_templates[$name];
        } else {
             $check_stmt = $pdo->prepare("SELECT id FROM message_templates WHERE name = ? AND user_id = ?");
             $check_stmt->execute([$name, $current_user_id]);
             $existing_id = $check_stmt->fetchColumn();
             if ($existing_id) {
                 $template_id = $existing_id;
             }
        }

        if ($template_id) {
            // Update existing template - This fixes the issue of "Default Approved" templates that are actually Rejected/Pending on Meta
            // We OVERWRITE the local status with the Meta status.
            $stmt = $pdo->prepare("UPDATE message_templates SET
                status = ?,
                category = ?,
                body = ?,
                header = ?,
                header_type = ?,
                footer = ?,
                quick_replies = ?,
                variables = ?,
                buttons_data = ?
                WHERE id = ?");
            $stmt->execute([
                $status,
                $category,
                $body,
                $header,
                $header_type,
                $footer,
                $quick_replies_json,
                $variables_json,
                $buttons_data_json,
                $template_id
            ]);
            $updated_count++;
        } elseif ($status === 'APPROVED') {
            // Insert NEW template ONLY if APPROVED
            $stmt = $pdo->prepare("INSERT INTO message_templates (
                user_id, name, category, body, header, header_type, footer, quick_replies, variables, buttons_data, status, language, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $current_user_id,
                $name,
                $category,
                $body,
                $header,
                $header_type,
                $footer,
                $quick_replies_json,
                $variables_json,
                $buttons_data_json,
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
