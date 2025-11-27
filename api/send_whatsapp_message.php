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

    if (!$input || !isset($input['conversation_id'])) {
        throw new Exception('Invalid input. Missing conversation_id.');
    }

    $conversationId = $input['conversation_id'];
    $content = $input['content'] ?? '';
    $userId = $_SESSION['user_id'];
    $scheduledAt = $input['scheduled_at'] ?? null;
    $messageType = $input['type'] ?? 'text'; // 'text', 'button', 'list', 'note'
    $interactiveData = $input['interactive_data'] ?? null;
    $attachmentUrl = $input['attachment_url'] ?? null;

    // Detect media type if attachment is present
    if ($attachmentUrl) {
        $ext = strtolower(pathinfo($attachmentUrl, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $messageType = 'image';
        } elseif (in_array($ext, ['mp4', '3gp'])) {
            $messageType = 'video';
        } elseif (in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'])) {
            $messageType = 'document';
        } elseif (in_array($ext, ['aac', 'amr', 'mp3', 'm4a', 'ogg'])) {
            $messageType = 'audio';
        } else {
            // Default to document for unknown types if needed, or stick to image?
            // Safer to assume document if not image/video/audio
            $messageType = 'document';
        }
        log_send_debug("Attachment detected: $attachmentUrl. Type set to: $messageType");
    }

    log_send_debug("Attempting to process message. User: $userId, Conv: $conversationId, Type: $messageType, Scheduled: " . ($scheduledAt ?: 'No'));

    // Check if it's a scheduled message
    if ($scheduledAt) {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO messages
            (conversation_id, sender_type, user_id, content, message_type, interactive_data, scheduled_at, status, created_at, sent_at)
            VALUES
            (:conv_id, 'user', :user_id, :content, :msg_type, :int_data, :scheduled, 'scheduled', NOW(), NOW())
        ");

        $stmt->execute([
            ':conv_id' => $conversationId,
            ':user_id' => $userId,
            ':content' => $attachmentUrl ? $attachmentUrl . ' ' . $content : $content, // Store URL in content for scheduled
            ':msg_type' => $messageType,
            ':int_data' => is_array($interactiveData) ? json_encode($interactiveData) : $interactiveData,
            ':scheduled' => $scheduledAt
        ]);

        $pdo->commit();
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Message scheduled successfully.']);
        exit;
    }

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
            $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM settings LIMIT 1");
            $stmt->execute();
            $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($globalSettings) {
                $whatsappToken = $globalSettings['whatsapp_access_token'] ?? null;
                $whatsappPhoneId = $globalSettings['whatsapp_phone_number_id'] ?? null;
            }
        } catch (PDOException $e) {
            // Ignore
        }
    }

    if (empty($whatsappToken) || empty($whatsappPhoneId)) {
        log_send_debug("Credentials missing.");
        throw new Exception('WhatsApp API settings are not configured.');
    }

    // Enhanced logging for debugging
    log_send_debug("Using PhoneID: {$whatsappPhoneId} and a Token.");

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

    // Check if it's a new conversation and handle template logic
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ?");
    $stmt->execute([$conversationId]);
    $messageCount = $stmt->fetchColumn();

    if ($messageCount == 0) {
        // Business Initiated Conversation: Must use a template
        if ($messageType !== 'template') {
            throw new Exception('New conversations must be initiated with a message template.');
        }
    }

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
    ];

    if ($messageType === 'text') {
        $postData['type'] = 'text';
        $postData['text'] = ['body' => $content];
    } elseif ($messageType === 'interactive') {
        $postData['type'] = 'interactive';
        $postData['interactive'] = is_string($interactiveData) ? json_decode($interactiveData, true) : $interactiveData;
    } elseif ($messageType === 'template') {
        $postData['type'] = 'template';
        $postData['template'] = is_string($interactiveData) ? json_decode($interactiveData, true) : $interactiveData;
    } elseif (in_array($messageType, ['image', 'video', 'document', 'audio'])) {
        $postData['type'] = $messageType;
        // Construct full URL if it's relative
        $fullUrl = $attachmentUrl;
        if (!preg_match('/^http/', $fullUrl)) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
            $host = $_SERVER['HTTP_HOST'];
            $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\'); // Go up one level from /api/
            $fullUrl = "$protocol://$host$base/$attachmentUrl";
        }

        $postData[$messageType] = [
            'link' => $fullUrl
        ];
        // Add caption for types that support it
        if ($content && $messageType !== 'audio') {
            $postData[$messageType]['caption'] = $content;
        }
        // Document needs a filename for better UX
        if ($messageType === 'document') {
            $postData[$messageType]['filename'] = basename($attachmentUrl);
        }
    }

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

    // Extract Provider Message ID (WAMID)
    $providerMessageId = $responseData['messages'][0]['id'] ?? null;

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

    $senderType = 'agent';

    // Dynamic Insert Logic
    // FIX: For interactive messages, the actual content for DB storage MUST be the JSON payload.
    // For Media messages, store URL and caption
    $dbContent = $content;
    if ($messageType === 'interactive' && !empty($interactiveData)) {
        $dbContent = is_array($interactiveData) ? json_encode($interactiveData) : $interactiveData;
    } elseif ($attachmentUrl) {
        // Store only the URL if no caption, or combine?
        // Generally good to store the URL or a JSON representation.
        // Let's store URL. Frontend handles rendering based on extension or message_type?
        // Frontend renderer currently checks for image extension in content string.
        // So we can store just the URL, or "Caption \n URL".
        // But message_type column exists now.
        if ($content) {
            $dbContent = $attachmentUrl . "\n" . $content;
        } else {
            $dbContent = $attachmentUrl;
        }
    }

    $columns = "conversation_id, sender_type, user_id, content, message_type, created_at, sent_at, status";
    $values = "?, ?, ?, ?, ?, NOW(), NOW(), 'sent'";
    $params = [$conversationId, $senderType, $userId, $dbContent, $messageType];

    if ($hasTenantId) {
        $columns .= ", tenant_id";
        $values .= ", ?";
        $params[] = $userId;
    }

    // Always try to insert provider_message_id if we have it (db.php handles schema migration)
    if ($providerMessageId) {
        $columns .= ", provider_message_id";
        $values .= ", ?";
        $params[] = $providerMessageId;
        log_send_debug("Saving Provider ID: $providerMessageId");
    }

    $stmt = $pdo->prepare("INSERT INTO messages ($columns) VALUES ($values)");
    $stmt->execute($params);

    $previewText = ($messageType === 'text') ? "You: " . $content : "You sent a " . $messageType;
    $stmt = $pdo->prepare("UPDATE conversations SET last_message_preview = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$previewText, $conversationId]);

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