<?php
// api/add_template.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';
require_once 'get_facebook_credentials.php'; // Include the centralized credentials function

$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$name = trim($data['name'] ?? '');
$category = trim($data['category'] ?? 'TRANSACTIONAL');
$body = trim($data['body'] ?? '');
$header = trim($data['header'] ?? null);
$footer = trim($data['footer'] ?? null);
$quick_replies_raw = trim($data['quick_replies'] ?? '');
$variable_examples = $data['variable_examples'] ?? [];

if (empty($name) || empty($body) || empty($category)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Template name, body, and category are required.']);
    exit();
}

$quick_replies_json = null;
$buttons_data_json = null;

if (!empty($quick_replies_raw)) {
    $replies_array = array_map('trim', explode(',', $quick_replies_raw));
    $quick_replies_json = json_encode($replies_array);

    // Construct buttons_data for new schema support
    $buttons_data = [];
    foreach ($replies_array as $reply) {
        $buttons_data[] = ['type' => 'QUICK_REPLY', 'text' => $reply];
    }
    $buttons_data_json = json_encode($buttons_data);
}

// Extract variable names like {{customer_name}} -> customer_name
preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $body, $matches);
$variables_json = !empty($matches[1]) ? json_encode($matches[1]) : null;

// Determine Header Type
$header_type = 'NONE';
if (!empty($header)) {
    $header_type = 'TEXT'; // Default to TEXT if header exists from this form
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO message_templates (user_id, name, category, body, header, footer, quick_replies, variables, status, language, header_type, buttons_data)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', 'en_US', ?, ?)"
    );
    
    $stmt->execute([
        $userId,
        $name,
        $category,
        $body,
        empty($header) ? null : $header,
        empty($footer) ? null : $footer,
        $quick_replies_json,
        $variables_json,
        $header_type,
        $buttons_data_json
    ]);

    $last_insert_id = $pdo->lastInsertId();

    // --- Meta API Integration ---
    $credentials = getFacebookCredentials($userId);
    $waba_id = $credentials['waba_id'];
    $access_token = $credentials['access_token'];

    // Replace {{variable_name}} with {{1}}, {{2}} etc. for Meta's format
    $meta_body = preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', function($match) {
        static $i = 1;
        return '{{' . $i++ . '}}';
    }, $body);

    $components = [['type' => 'BODY', 'text' => $meta_body]];

    // Add header with variable examples if they exist
    if (!empty($header)) {
        $header_component = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
        if (strpos($header, '{{1}}') !== false && !empty($variable_examples)) {
            $header_component['example'] = [
                'header_text' => [array_values($variable_examples)[0]]
            ];
        }
        $components[] = $header_component;
    }

    if (!empty($footer)) {
        $components[] = ['type' => 'FOOTER', 'text' => $footer];
    }

    if (!empty($quick_replies_raw)) {
        $buttons = [];
        foreach (explode(',', $quick_replies_raw) as $reply) {
            if(trim($reply) !== '') {
                $buttons[] = ['type' => 'QUICK_REPLY', 'text' => trim($reply)];
            }
        }
        if(!empty($buttons)) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }
    }

    // Add body examples if variables are present
    foreach ($components as &$component) {
        if ($component['type'] === 'BODY' && !empty($variable_examples)) {
            $component['example'] = [
                'body_text' => [array_values($variable_examples)]
            ];
        }
    }
    unset($component);


    $payload = [
        'name' => strtolower(str_replace(' ', '_', $name)),
        'language' => 'en_US',
        'category' => $category,
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
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code < 200 || $http_code >= 300) {
        $error_response = json_decode($response, true);
        $errorMessage = $error_response['error']['message'] ?? 'Unknown Meta API error';
        if ($curl_error) {
            $errorMessage = "cURL Error: " . $curl_error;
        }
        throw new Exception("Meta API Error: " . $errorMessage);
    }

    $meta_response = json_decode($response, true);
    if (isset($meta_response['id'])) {
        $update_stmt = $pdo->prepare(
            "UPDATE message_templates
             SET meta_template_id = ?, meta_template_name = ?, status = 'PENDING_APPROVAL'
             WHERE id = ?"
        );
        $update_stmt->execute([
            $meta_response['id'],
            $payload['name'],
            $last_insert_id
        ]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "Template '{$name}' created and submitted for approval."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log("Add Template Error: " . $e->getMessage() . " Payload: " . json_encode($payload ?? []));
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
