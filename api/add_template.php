<?php
// api/add_template.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$name = trim($data['name'] ?? '');
$body = trim($data['body'] ?? '');
$header = trim($data['header'] ?? null);
$footer = trim($data['footer'] ?? null);
$quick_replies_raw = trim($data['quick_replies'] ?? '');
$variables_raw = $data['variables'] ?? [];

if (empty($name) || empty($body)) {
    echo json_encode(['status' => 'error', 'message' => 'Template name and body are required.']);
    exit();
}

// Andaa quick replies ziwe JSON string
$quick_replies = null;
if (!empty($quick_replies_raw)) {
    $replies_array = array_map('trim', explode(',', $quick_replies_raw));
    $quick_replies = json_encode($replies_array);
}

// Andaa variables ziwe JSON string
$variables = null;
if (!empty($variables_raw) && is_array($variables_raw)) {
    $variables = json_encode($variables_raw);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO message_templates (name, body, header, footer, quick_replies, variables, status) 
         VALUES (?, ?, ?, ?, ?, ?, 'PENDING')"
    );
    
    $stmt->execute([
        $name,
        $body,
        empty($header) ? null : $header,
        empty($footer) ? null : $footer,
        $quick_replies,
        $variables
    ]);

    $last_insert_id = $pdo->lastInsertId();

    // Meta API Integration
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT whatsapp_business_account_id, whatsapp_access_token FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_settings && !empty($user_settings['whatsapp_business_account_id']) && !empty($user_settings['whatsapp_access_token'])) {
        $waba_id = $user_settings['whatsapp_business_account_id'];
        $access_token = $user_settings['whatsapp_access_token'];
    } else {
        $settings_stmt = $pdo->prepare("SELECT whatsapp_business_account_id, whatsapp_access_token FROM settings WHERE id = 1");
        $settings_stmt->execute();
        $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

        if ($settings && !empty($settings['whatsapp_business_account_id']) && !empty($settings['whatsapp_access_token'])) {
            $waba_id = $settings['whatsapp_business_account_id'];
            $access_token = $settings['whatsapp_access_token'];
        }
    }

    if (isset($waba_id) && isset($access_token)) {

        $meta_body = $body;
        if (!empty($variables_raw)) {
            $i = 1;
            foreach ($variables_raw as $var) {
                $meta_body = str_replace("{{{$var}}}", "{{{$i}}}", $meta_body);
                $i++;
            }
        }

        $components = [
            ['type' => 'BODY', 'text' => $meta_body]
        ];
        if (!empty($header)) {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
        }
        if (!empty($footer)) {
            $components[] = ['type' => 'FOOTER', 'text' => $footer];
        }
        if (!empty($quick_replies_raw)) {
            $buttons = [];
            foreach (explode(',', $quick_replies_raw) as $reply) {
                $buttons[] = ['type' => 'QUICK_REPLY', 'text' => trim($reply)];
            }
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        // Sanitize the template name for Meta API requirements (lowercase, no special chars)
        $clean_name = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);
        $meta_template_name = strtolower(str_replace(' ', '_', $clean_name));

        $payload = [
            'name' => $meta_template_name,
            'language' => 'en_US',
            'category' => 'TRANSACTIONAL',
            'components' => $components
        ];

        $url = "https://graph.facebook.com/v21.0/{$waba_id}/message_templates";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$access_token}",
            "Content-Type: application/json"
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code < 200 || $http_code >= 300) {
            $error_response = json_decode($response, true);
            $error_message = $error_response['error']['message'] ?? 'Unknown error';
            $error_details = $error_response['error']['error_data']['details'] ?? '';
            $full_error_message = "Meta API Error: {$error_message}";
            if (!empty($error_details)) {
                $full_error_message .= " (Details: {$error_details})";
            }
            throw new Exception($full_error_message);
        }

        // Save Meta's response details
        $meta_response = json_decode($response, true);
        if (isset($meta_response['id'])) {
            $update_stmt = $pdo->prepare(
                "UPDATE message_templates
                 SET meta_template_id = ?, meta_template_name = ?
                 WHERE id = ?"
            );
            $update_stmt->execute([
                $meta_response['id'],
                $meta_template_name, // The sanitized name we sent to Meta
                $last_insert_id
            ]);
        }
    } else {
        // If no credentials found, just commit the local save without hitting Meta API
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "Template '{$name}' created and submitted for approval."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
