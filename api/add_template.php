<?php
// api/add_template.php
header('Content-Type: application/json');
// Ensure no output before JSON
ob_start();

require_once 'db.php';
require_once 'get_facebook_credentials.php';

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        ob_clean(); // Clear buffer
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server Error: ' . $error['message']]);
    }
});

session_start();

if (!isset($_SESSION['user_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit();
}

$name = trim($data['name'] ?? '');
$category = trim($data['category'] ?? 'TRANSACTIONAL');
$body = trim($data['body'] ?? '');
$header = trim($data['header'] ?? null);
$footer = trim($data['footer'] ?? null);
$quick_replies_raw = trim($data['quick_replies'] ?? '');
$buttons_input = $data['buttons'] ?? []; // Array of {type, text, url, phone_number}
$variable_examples = $data['variable_examples'] ?? [];

if (empty($name) || empty($body) || empty($category)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Template name, body, and category are required.']);
    exit();
}

// Prepare Data for Database
$quick_replies_json = null;
$buttons_data_json = null;

// Handle Buttons Logic (Prioritize new 'buttons' array, fallback to 'quick_replies' string)
$db_buttons = [];

if (!empty($buttons_input) && is_array($buttons_input)) {
    // Validate and Clean buttons
    foreach ($buttons_input as $btn) {
        $cleanBtn = [
            'type' => $btn['type'] ?? 'QUICK_REPLY',
            'text' => trim($btn['text'] ?? '')
        ];
        if ($cleanBtn['type'] === 'URL') {
            $cleanBtn['url'] = trim($btn['url'] ?? '');
        }
        if ($cleanBtn['type'] === 'PHONE_NUMBER') {
            $cleanBtn['phone_number'] = trim($btn['phone_number'] ?? '');
        }
        if (!empty($cleanBtn['text'])) {
            $db_buttons[] = $cleanBtn;
        }
    }
} elseif (!empty($quick_replies_raw)) {
    // Fallback legacy logic
    foreach (explode(',', $quick_replies_raw) as $reply) {
        if (trim($reply) !== '') {
            $db_buttons[] = ['type' => 'QUICK_REPLY', 'text' => trim($reply)];
        }
    }
}

if (!empty($db_buttons)) {
    $buttons_data_json = json_encode($db_buttons);
    // Populate quick_replies CSV for legacy views (only include QR texts)
    $qr_texts = array_column(array_filter($db_buttons, function($b) { return $b['type'] === 'QUICK_REPLY'; }), 'text');
    $quick_replies_raw = implode(',', $qr_texts);
    $quick_replies_json = json_encode($qr_texts);
}

// Extract variable names
preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $body, $matches);
$variables_json = !empty($matches[1]) ? json_encode($matches[1]) : null;

// Determine Header Type
$header_type = 'NONE';
if (!empty($header)) {
    $header_type = 'TEXT';
}

try {
    // --- DB Insertion ---
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

    // --- Meta API Payload Construction ---
    $credentials = getFacebookCredentials($userId);
    if (!$credentials) throw new Exception("Facebook credentials not found for user.");

    $waba_id = $credentials['waba_id'];
    $access_token = $credentials['access_token'];

    // Format Body Variables: {{customer_name}} -> {{1}}
    $meta_body = preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', function($match) {
        static $i = 1;
        return '{{' . $i++ . '}}';
    }, $body);

    $components = [['type' => 'BODY', 'text' => $meta_body]];

    // Header
    if (!empty($header)) {
        $header_component = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
        if (strpos($header, '{{1}}') !== false && !empty($variable_examples)) {
            // Basic example handling, usually needs improvement for multiple header vars
            $header_component['example'] = [
                'header_text' => [array_values($variable_examples)[0]] // Just take the first one
            ];
        }
        $components[] = $header_component;
    }

    // Footer
    if (!empty($footer)) {
        $components[] = ['type' => 'FOOTER', 'text' => $footer];
    }

    // Buttons
    if (!empty($db_buttons)) {
        $meta_buttons = [];
        foreach ($db_buttons as $btn) {
            if ($btn['type'] === 'QUICK_REPLY') {
                $meta_buttons[] = ['type' => 'QUICK_REPLY', 'text' => $btn['text']];
            } elseif ($btn['type'] === 'URL') {
                $meta_buttons[] = ['type' => 'URL', 'text' => $btn['text'], 'url' => $btn['url']];
            } elseif ($btn['type'] === 'PHONE_NUMBER') {
                $meta_buttons[] = ['type' => 'PHONE_NUMBER', 'text' => $btn['text'], 'phone_number' => $btn['phone_number']];
            }
        }

        if (!empty($meta_buttons)) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $meta_buttons];
        }
    }

    // Body Examples
    // This is tricky. If body has {{1}}, {{2}}, Meta REQUIRES examples.
    // We rely on $variable_examples passed from frontend (key-value)
    // We need to order them based on occurrence in body.
    if (!empty($matches[1])) {
        $body_example_values = [];
        foreach ($matches[1] as $var_name) {
            $body_example_values[] = $variable_examples[$var_name] ?? 'sample';
        }

        // Find body component and add example
        foreach ($components as &$c) {
            if ($c['type'] === 'BODY') {
                $c['example'] = ['body_text' => [$body_example_values]];
                break;
            }
        }
    }

    $payload = [
        'name' => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name)), // Strict name sanitization
        'language' => 'en_US',
        'category' => $category,
        'components' => $components
    ];

    // --- Meta API Request ---
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

        // Log detailed error for debugging
        error_log("Meta Template Creation Failed: " . $response);

        throw new Exception("Meta API Error: " . $errorMessage);
    }

    $meta_response = json_decode($response, true);
    if (isset($meta_response['id'])) {
        $update_stmt = $pdo->prepare(
            "UPDATE message_templates
             SET meta_template_id = ?, meta_template_name = ?, status = 'PENDING'
             WHERE id = ?"
        );
        $update_stmt->execute([
            $meta_response['id'],
            $payload['name'],
            $last_insert_id
        ]);
    }

    $pdo->commit();
    ob_clean();
    echo json_encode(['status' => 'success', 'message' => "Template '{$name}' created and submitted for approval."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean();
    http_response_code(500);
    error_log("Add Template Exception: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
ob_end_flush();
?>
