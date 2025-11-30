<?php
// api/send_whatsapp_message.php

// 1. LOGGING SETUP (Muhimu kwa Debugging)
$log_file = __DIR__ . '/../webhook_debug.log'; 
function log_send_debug($msg) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[Send Debug] $timestamp - $msg\n", FILE_APPEND);
}

// 2. ERROR HANDLING: Hakikisha tunarudisha JSON hata kukitokea Fatal Error
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        // Futa output yoyote iliyotangulia isiharibu JSON
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $msg = 'Critical Server Error: ' . $error['message'] . ' in ' . $error['file'] . ' line ' . $error['line'];
        log_send_debug($msg);
        echo json_encode(['success' => false, 'message' => $msg]);
    }
});

// Anza Output Buffering
ob_start();

header('Content-Type: application/json');

try {
    require_once 'db.php';
    session_start();

    // Security Check
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
    $messageType = $input['type'] ?? 'text'; // 'text', 'template', 'image', etc.
    $interactiveData = $input['interactive_data'] ?? null;
    $attachmentUrl = $input['attachment_url'] ?? null;

    // --- FIX 1: MEDIA TYPE DETECTION ---
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
            $messageType = 'document'; // Fallback
        }
        log_send_debug("Attachment detected: $attachmentUrl. Type set to: $messageType");
    }

    // --- FIX 2: SCHEDULED TIME FORMATTING ---
    if ($scheduledAt) {
        // Badilisha '2025-11-30T10:20' kuwa '2025-11-30 10:20:00'
        $scheduledAt = str_replace('T', ' ', $scheduledAt);
        // Ongeza sekunde kama hazipo
        if (strlen($scheduledAt) == 16) {
            $scheduledAt .= ':00';
        }

        // Hifadhi kwenye DB kama 'scheduled'
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO messages 
            (conversation_id, sender_type, user_id, content, message_type, interactive_data, scheduled_at, status, created_at, sent_at) 
            VALUES 
            (:conv_id, 'user', :user_id, :content, :msg_type, :int_data, :scheduled, 'scheduled', NOW(), NOW())
        ");

        $dbContent = $attachmentUrl ? $attachmentUrl . ' ' . $content : $content;
        
        $stmt->execute([
            ':conv_id' => $conversationId,
            ':user_id' => $userId,
            ':content' => $dbContent,
            ':msg_type' => $messageType,
            ':int_data' => is_array($interactiveData) ? json_encode($interactiveData) : $interactiveData,
            ':scheduled' => $scheduledAt
        ]);

        $pdo->commit();
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Message scheduled successfully.']);
        exit;
    }

    // 3. FETCH CREDENTIALS (WABA SETTINGS)
    $whatsappToken = null;
    $whatsappPhoneId = null;

    // Jaribu settings za User kwanza (Multi-tenant)
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userSettings && !empty($userSettings['whatsapp_access_token'])) {
        $whatsappToken = $userSettings['whatsapp_access_token'];
        $whatsappPhoneId = $userSettings['whatsapp_phone_number_id'];
    } else {
        // Fallback: Global settings
        $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM settings LIMIT 1");
        $stmt->execute();
        $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($globalSettings) {
            $whatsappToken = $globalSettings['whatsapp_access_token'];
            $whatsappPhoneId = $globalSettings['whatsapp_phone_number_id'];
        }
    }

    if (empty($whatsappToken) || empty($whatsappPhoneId)) {
        throw new Exception('WhatsApp API settings are not configured.');
    }

    // 4. GET RECIPIENT PHONE
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact) {
        throw new Exception('Contact not found for this conversation.');
    }
    $recipientPhoneNumber = $contact['phone_number'];

    // --- FIX 3: PHONE NUMBER NORMALIZATION (CRITICAL FOR SINGLE TICKS) ---
    // Ondoa alama zote, baki na namba tu
    $recipientPhoneNumber = preg_replace('/[^0-9]/', '', $recipientPhoneNumber);

    // Case 1: Imeanzia na '2550...' (Hili ndilo kosa kubwa linaloleta Single Tick)
    // Mfano: 2550712345678 -> Tunatoa hiyo '0' ibaki 255712345678
    if (substr($recipientPhoneNumber, 0, 4) === '2550') {
        $recipientPhoneNumber = '255' . substr($recipientPhoneNumber, 4);
    }
    // Case 2: Imeanzia na '0...' (Mfano: 0712...) -> Badili iwe 255712...
    elseif (substr($recipientPhoneNumber, 0, 1) === '0') {
        $recipientPhoneNumber = '255' . substr($recipientPhoneNumber, 1);
    }
    // Case 3: Imeanzia na '7...' (Haina code) -> Ongeza 255
    elseif (strlen($recipientPhoneNumber) === 9) {
        $recipientPhoneNumber = '255' . $recipientPhoneNumber;
    }

    log_send_debug("Sending to Normalized Phone: $recipientPhoneNumber");


    // 5. PREPARE PAYLOAD FOR META API
    $apiUrl = "https://graph.facebook.com/v21.0/{$whatsappPhoneId}/messages";
    $postData = [
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $recipientPhoneNumber,
    ];

    if ($messageType === 'text') {
        $postData['type'] = 'text';
        $postData['text'] = ['body' => $content];
    } 
    elseif ($messageType === 'template') {
        $postData['type'] = 'template';
        // Hakikisha structure ya template iko sawa
        $templateData = is_string($interactiveData) ? json_decode($interactiveData, true) : $interactiveData;
        
        $postData['template'] = [
            'name' => $templateData['name'],
            'language' => $templateData['language'] ?? ['code' => 'en_US'],
            'components' => $templateData['components'] ?? []
        ];
    }
    elseif ($messageType === 'interactive') {
        $postData['type'] = 'interactive';
        $postData['interactive'] = is_string($interactiveData) ? json_decode($interactiveData, true) : $interactiveData;
    } 
    elseif (in_array($messageType, ['image', 'video', 'document', 'audio'])) {
        $postData['type'] = $messageType;
        
        // Hakikisha URL ni absolute (ina http/https)
        $fullUrl = $attachmentUrl;
        if (!preg_match('/^http/', $fullUrl)) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
            $host = $_SERVER['HTTP_HOST'];
            $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\'); // Parent of /api/
            $fullUrl = "$protocol://$host$base/$attachmentUrl";
        }

        $postData[$messageType] = [
            'link' => $fullUrl
        ];
        
        // Caption haitumiki kwenye audio/sticker
        if ($content && $messageType !== 'audio') {
            $postData[$messageType]['caption'] = $content;
        }
        if ($messageType === 'document') {
            $postData[$messageType]['filename'] = basename($attachmentUrl);
        }
    }

    // 6. SEND TO META (CURL)
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
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log Response for Debugging
    log_send_debug("Meta API Response ($httpcode): " . $response);

    if ($httpcode < 200 || $httpcode >= 300) {
        $responseData = json_decode($response, true);
        $errorMsg = $responseData['error']['message'] ?? "Unknown API Error ($httpcode)";
        throw new Exception("Meta API Error: " . $errorMsg);
    }

    $responseData = json_decode($response, true);
    $providerMessageId = $responseData['messages'][0]['id'] ?? null;

    // 7. SAVE TO DATABASE
    $pdo->beginTransaction();

    // Prepare Content for DB
    $dbContent = $content;
    if (($messageType === 'template' || $messageType === 'interactive') && !empty($interactiveData)) {
        // Ikiwa ni template, jaribu kuweka maandishi ya preview ikiwa yapo, vinginevyo weka JSON
        if (!empty($content) && $content !== 'Interactive Message') {
            $dbContent = $content;
        } else {
            $dbContent = is_array($interactiveData) ? json_encode($interactiveData) : $interactiveData;
        }
    } elseif ($attachmentUrl) {
        $dbContent = $attachmentUrl . ($content ? " " . $content : "");
    }

    // Check columns dynamically (tenant_id, provider_message_id)
    // Hii inasaidia kuzuia error kama column bado haijaongezwa
    $columns = "conversation_id, sender_type, user_id, content, message_type, created_at, sent_at, status";
    $placeholders = "?, ?, ?, ?, ?, NOW(), NOW(), 'sent'";
    $params = [$conversationId, 'agent', $userId, $dbContent, $messageType];

    // Ongeza Provider ID
    if ($providerMessageId) {
        $columns .= ", provider_message_id";
        $placeholders .= ", ?";
        $params[] = $providerMessageId;
    }

    // Ongeza Tenant ID (Kama ipo kwenye table)
    // Note: Hii check ni nzito kidogo, kwa production bora uwe na uhakika ipo.
    // Kwa sasa tunaiacha ili isibreak kodi kama hujaiweka bado.
    
    $stmt = $pdo->prepare("INSERT INTO messages ($columns) VALUES ($placeholders)");
    $stmt->execute($params);

    // Update Conversation Last Message
    $previewText = ($messageType === 'text') ? "You: " . substr($content, 0, 50) : "You sent a " . $messageType;
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