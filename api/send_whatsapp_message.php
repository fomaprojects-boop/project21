<?php
// api/send_whatsapp_message.php

// Ensure JSON response even on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR)) {
        // Clean buffer
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Critical Server Error: ' . $error['message']]);
    }
});

// Start Output Buffering to prevent stray HTML
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

    // 1. Fetch settings for WhatsApp API (Priority: User settings > Global settings fallback)
    $whatsappToken = null;
    $whatsappPhoneId = null;

    // Try to get credentials from the current user
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userSettings && !empty($userSettings['whatsapp_access_token']) && !empty($userSettings['whatsapp_phone_number_id'])) {
        $whatsappToken = $userSettings['whatsapp_access_token'];
        $whatsappPhoneId = $userSettings['whatsapp_phone_number_id'];
    } else {
        // Fallback to global settings (if applicable for single-tenant deployments)
        try {
            $stmt = $pdo->prepare("SELECT whatsapp_token, whatsapp_phone_id FROM settings LIMIT 1");
            $stmt->execute();
            $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($globalSettings) {
                $whatsappToken = $globalSettings['whatsapp_token'] ?? null;
                $whatsappPhoneId = $globalSettings['whatsapp_phone_id'] ?? null;
            }
        } catch (PDOException $e) {
            // Ignore if table/columns don't exist
        }
    }

    if (empty($whatsappToken) || empty($whatsappPhoneId)) {
        throw new Exception('WhatsApp API settings are not configured. Please connect your WhatsApp account in Settings.');
    }

    // 2. Get the recipient's phone number
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact) {
        throw new Exception('Contact not found for this conversation.');
    }
    $recipientPhoneNumber = $contact['phone_number'];

    // Sanitize and Normalize Phone Number
    // Remove non-digits (and keep + if present, but Graph API needs pure digits usually, or no +)
    // Actually Graph API works well with pure digits including country code.
    $recipientPhoneNumber = preg_replace('/[^0-9]/', '', $recipientPhoneNumber);

    // Tanzanian Specific Logic (Optional, but good for local robustness)
    if (substr($recipientPhoneNumber, 0, 1) === '0') {
        $recipientPhoneNumber = '255' . substr($recipientPhoneNumber, 1);
    } elseif (strlen($recipientPhoneNumber) === 9) {
        $recipientPhoneNumber = '255' . $recipientPhoneNumber;
    }

    // 3. Send via WhatsApp Graph API v21.0
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
        $curlError = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL Error: ' . $curlError);
    }
    curl_close($ch);

    $responseData = json_decode($response, true);

    // Check for API errors
    if ($httpcode < 200 || $httpcode >= 300) {
        $errorMsg = $responseData['error']['message'] ?? 'Unknown Graph API error';
        $errorDetail = isset($responseData['error']['error_data']['details']) ? " (" . $responseData['error']['error_data']['details'] . ")" : "";
        throw new Exception('WhatsApp API Error: ' . $errorMsg . $errorDetail);
    }

    // 4. Save to Database
    // We save it as 'agent' (sent by user)
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, created_at, status) VALUES (?, 'agent', ?, ?, NOW(), 'sent')");
    $stmt->execute([$conversationId, $userId, $content]);

    // Update Conversation Preview
    $stmt = $pdo->prepare("UPDATE conversations SET last_message_preview = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute(["You: " . $content, $conversationId]);

    $pdo->commit();

    ob_clean(); // Clear buffer before sending success JSON
    echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_clean(); // Clear buffer before sending error JSON
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
