<?php
// api/send_whatsapp_message.php

// Define Log Function locally or include config
$log_file = __DIR__ . '/../webhook_debug.log'; // Log to root webhook debug log for unified debugging
function log_send_debug($msg) {
    global $log_file;
    file_put_contents($log_file, "[Send Debug] " . date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

// Ensure JSON response even on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $msg = 'Critical Server Error: ' . $error['message'];
        log_send_debug($msg);
        echo json_encode(['success' => false, 'message' => $msg]);
    }
});

// Start Output Buffering
ob_start();

header('Content-Type: application/json');

try {
    require_once 'db.php';
    session_start();

    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized. Please log in.');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['conversation_id']) || !isset($input['content'])) {
        throw new Exception('Invalid input. Missing conversation_id or content.');
    }

    $conversationId = $input['conversation_id'];
    $content = $input['content'];
    $userId = $_SESSION['user_id'];

    log_send_debug("Attempting to send message. User: $userId, Conv: $conversationId");

    // 1. Fetch settings for WhatsApp API
    $whatsappToken = null;
    $whatsappPhoneId = null;

    // Try User Settings
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userSettings && !empty($userSettings['whatsapp_access_token']) && !empty($userSettings['whatsapp_phone_number_id'])) {
        $whatsappToken = $userSettings['whatsapp_access_token'];
        $whatsappPhoneId = $userSettings['whatsapp_phone_number_id'];
        log_send_debug("Using User Settings.");
    } else {
        // Fallback to global settings
        log_send_debug("User settings missing. Checking global settings.");
        try {
            $stmt = $pdo->prepare("SELECT whatsapp_token, whatsapp_phone_id FROM settings LIMIT 1");
            $stmt->execute();
            $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($globalSettings) {
                $whatsappToken = $globalSettings['whatsapp_token'] ?? null;
                $whatsappPhoneId = $globalSettings['whatsapp_phone_id'] ?? null;
            }
        } catch (PDOException $e) {
            // Ignore
        }
    }

    if (empty($whatsappToken) || empty($whatsappPhoneId)) {
        log_send_debug("Credentials missing.");
        throw new Exception('WhatsApp API settings are not configured.');
    }

    // 2. Get Recipient Phone
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact) {
        log_send_debug("Contact not found for Conv ID: $conversationId");
        throw new Exception('Contact not found for this conversation.');
    }
    $recipientPhoneNumber = $contact['phone_number'];
    log_send_debug("Original Phone: $recipientPhoneNumber");

    // Normalize Phone
    $recipientPhoneNumber = preg_replace('/[^0-9]/', '', $recipientPhoneNumber);
    if (substr($recipientPhoneNumber, 0, 1) === '0') {
        $recipientPhoneNumber = '255' . substr($recipientPhoneNumber, 1);
    } elseif (strlen($recipientPhoneNumber) === 9) {
        $recipientPhoneNumber = '255' . $recipientPhoneNumber;
    }
    log_send_debug("Normalized Phone: $recipientPhoneNumber");

    // 3. Send via Graph API
    $apiUrl = "https://graph.facebook.com/v21.0/{$whatsappPhoneId}/messages";
    $postData = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $recipientPhoneNumber,
        'type' => 'text',
        'text' => ['body' => $content]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $whatsappToken
    ]);

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        log_send_debug("cURL Error: $error");
        throw new Exception("cURL Error: $error");
    }
    curl_close($ch);

    log_send_debug("API Response ($httpcode): " . $response);

    $responseData = json_decode($response, true);

    if ($httpcode < 200 || $httpcode >= 300) {
        $errorMsg = $responseData['error']['message'] ?? 'Unknown Graph API error';
        throw new Exception('WhatsApp API Error: ' . $errorMsg);
    }

    // 4. Save to DB
    // Check for tenant_id column
    $hasTenantId = false;
    try {
        $res = $pdo->query("SHOW COLUMNS FROM messages LIKE 'tenant_id'");
        if ($res && $res->rowCount() > 0) {
            $hasTenantId = true;
        }
    } catch (Exception $ex) {}

    $pdo->beginTransaction();

    // Determine safe sender_type (likely 'user' based on common ENUM('user','contact') schemas)
    // The error "Data truncated for column 'sender_type'" suggests 'agent' (5 chars) might be invalid if ENUM is strict.
    $senderType = 'user';

    if ($hasTenantId) {
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, tenant_id, content, created_at, sent_at, status) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'sent')");
        $stmt->execute([$conversationId, $senderType, $userId, $userId, $content]); // Assume user_id is tenant_id
    } else {
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, sent_at, status) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'sent')");
        $stmt->execute([$conversationId, $senderType, $userId, $content]);
    }

    $stmt = $pdo->prepare("UPDATE conversations SET last_message_preview = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute(["You: " . $content, $conversationId]);

    $pdo->commit();

    ob_clean();
    echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean();
    log_send_debug("Exception: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
