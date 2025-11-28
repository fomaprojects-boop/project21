<?php
// api/add_template.php
header('Content-Type: application/json');
ob_start();

// 1. Load Dependencies
require_once 'config.php'; 
require_once 'db.php';     

// Error Handler
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server Error: ' . $error['message']]);
    }
});

session_start();

// 2. Auth Check
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

// 3. Sanitize Inputs
$name = trim($data['name'] ?? '');
$category = trim($data['category'] ?? 'TRANSACTIONAL'); 
$body = trim($data['body'] ?? '');
$header = trim($data['header'] ?? null);
$footer = trim($data['footer'] ?? null);
$header_type = trim($data['header_type'] ?? 'NONE');
$quick_replies_raw = trim($data['quick_replies'] ?? '');
$buttons_input = $data['buttons'] ?? []; 
$variable_examples = $data['variable_examples'] ?? [];

if (empty($name) || empty($body) || empty($category)) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Template name, body, and category are required.']);
    exit();
}

// 4. Process Buttons
$db_buttons = [];
if (!empty($buttons_input) && is_array($buttons_input)) {
    foreach ($buttons_input as $btn) {
        $cleanBtn = ['type' => $btn['type'] ?? 'QUICK_REPLY', 'text' => trim($btn['text'] ?? '')];
        if ($cleanBtn['type'] === 'URL') $cleanBtn['url'] = trim($btn['url'] ?? '');
        if ($cleanBtn['type'] === 'PHONE_NUMBER') $cleanBtn['phone_number'] = trim($btn['phone_number'] ?? '');
        if (!empty($cleanBtn['text'])) $db_buttons[] = $cleanBtn;
    }
} elseif (!empty($quick_replies_raw)) {
    foreach (explode(',', $quick_replies_raw) as $reply) {
        if (trim($reply) !== '') $db_buttons[] = ['type' => 'QUICK_REPLY', 'text' => trim($reply)];
    }
}

$buttons_data_json = !empty($db_buttons) ? json_encode($db_buttons) : null;
$quick_replies_json = null; 

// Extract Variables
preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $body, $matches);
$variables_json = !empty($matches[1]) ? json_encode($matches[1]) : null;

// Normalize Header
if ($header_type === 'TEXT' && empty($header)) $header_type = 'NONE';
if ($header_type !== 'TEXT' && $header_type !== 'NONE') $header = null;

try {
    // --- HAPA NDIPO KULIPOKUWA NA TATIZO ---
    // Tumeondoa function ya nje, tunaweka SQL query hapa hapa
    
    $stmtCreds = $pdo->prepare("SELECT whatsapp_business_account_id, whatsapp_access_token FROM users WHERE id = ?");
    $stmtCreds->execute([$userId]);
    $userCreds = $stmtCreds->fetch(PDO::FETCH_ASSOC);

    // Kama credentials hazipo
    if (!$userCreds || empty($userCreds['whatsapp_access_token']) || empty($userCreds['whatsapp_business_account_id'])) {
        throw new Exception("WhatsApp Account not connected. Please connect in Settings.");
    }

    $waba_id = $userCreds['whatsapp_business_account_id'];
    $access_token = $userCreds['whatsapp_access_token'];
    // ----------------------------------------

    $pdo->beginTransaction();

    // --- STEP B: Save to Local Database ---
    $stmt = $pdo->prepare(
        "INSERT INTO message_templates (user_id, name, category, body, header, footer, quick_replies, variables, status, language, header_type, buttons_data)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', 'en_US', ?, ?)"
    );
    
    $stmt->execute([
        $userId, $name, $category, $body, 
        empty($header) ? null : $header, 
        empty($footer) ? null : $footer, 
        $quick_replies_json, $variables_json, 
        $header_type, $buttons_data_json
    ]);
    $last_insert_id = $pdo->lastInsertId();

    // --- STEP C: Prepare Meta API Payload ---
    $meta_body = preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', function($match) {
        static $i = 1; return '{{' . $i++ . '}}';
    }, $body);

    $components = [['type' => 'BODY', 'text' => $meta_body]];

    if (!empty($matches[1])) {
        $body_example_values = [];
        foreach ($matches[1] as $var_name) {
            $body_example_values[] = $variable_examples[$var_name] ?? 'sample';
        }
        $components[0]['example'] = ['body_text' => [$body_example_values]];
    }

    if ($header_type === 'TEXT' && !empty($header)) {
        $header_comp = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
        if (strpos($header, '{{1}}') !== false && !empty($variable_examples)) {
            $header_comp['example'] = ['header_text' => [array_values($variable_examples)[0]]];
        }
        $components[] = $header_comp;
    } elseif (in_array($header_type, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
        $components[] = ['type' => 'HEADER', 'format' => $header_type];
    }

    if (!empty($footer)) {
        $components[] = ['type' => 'FOOTER', 'text' => $footer];
    }

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

    $payload = [
        'name' => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name)), 
        'language' => 'en_US',
        'category' => $category,
        'components' => $components
    ];

    // --- STEP D: Send to Meta API ---
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
        $errorMessage = $error_response['error']['message'] ?? 'Unknown Meta API error';
        throw new Exception("Meta Rejected: " . $errorMessage);
    }

    // --- STEP E: Update Local DB ---
    $meta_response = json_decode($response, true);
    if (isset($meta_response['id'])) {
        $update_stmt = $pdo->prepare(
            "UPDATE message_templates SET meta_template_id = ?, meta_template_name = ?, status = 'PENDING' WHERE id = ?"
        );
        $update_stmt->execute([
            $meta_response['id'],
            $payload['name'],
            $last_insert_id
        ]);
    }

    $pdo->commit();
    ob_clean();
    echo json_encode(['status' => 'success', 'message' => "Template '{$name}' created and submitted successfully!"]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean();
    http_response_code(400); 
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
ob_end_flush();
?>