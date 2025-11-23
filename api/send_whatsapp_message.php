<?php
// api/send_whatsapp_message.php

require_once 'db.php';
require_once 'auth.php'; // Ensures the user is logged in

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['conversation_id']) || !isset($input['content'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input.']);
    exit;
}

$conversationId = $input['conversation_id'];
$content = $input['content'];
$userId = $_SESSION['user_id']; // The agent/user sending the message

try {
    // 1. Fetch settings for WhatsApp API (Priority: User settings > Global settings fallback)
    $whatsappToken = null;
    $whatsappPhoneId = null;

    // Try to get credentials from the current user (as set by Embedded Signup)
    try {
        $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userSettings) {
            $whatsappToken = $userSettings['whatsapp_access_token'] ?? null;
            $whatsappPhoneId = $userSettings['whatsapp_phone_number_id'] ?? null;
        }
    } catch (PDOException $e) {
        // If 'users' table doesn't have the columns yet, ignore and fallback
        // Log error if possible: error_log("User specific WhatsApp settings fetch failed: " . $e->getMessage());
    }

    // If not found in users (or query failed), try the legacy 'settings' table (fallback)
    if (empty($whatsappToken) || empty($whatsappPhoneId)) {
        try {
            $stmt = $pdo->prepare("SELECT whatsapp_token, whatsapp_phone_id FROM settings LIMIT 1");
            $stmt->execute();
            $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($globalSettings) {
                $whatsappToken = $globalSettings['whatsapp_token'] ?? null;
                $whatsappPhoneId = $globalSettings['whatsapp_phone_id'] ?? null;
            }
        } catch (PDOException $e) {
            // If global settings also fail, we have a bigger problem
            throw new Exception('Database error retrieving settings: ' . $e->getMessage());
        }
    }

    if (empty($whatsappToken) || empty($whatsappPhoneId)) {
        throw new Exception('WhatsApp API settings are not configured. Please connect your WhatsApp account in Settings.');
    }

    // 2. Get the recipient's phone number from the conversation
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversationId]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contact) {
        throw new Exception('Contact not found for this conversation.');
    }
    $recipientPhoneNumber = $contact['phone_number'];

    // 3. Send the message via WhatsApp API
    $apiUrl = "https://graph.facebook.com/v20.0/{$whatsappPhoneId}/messages";
    $postData = [
        'messaging_product' => 'whatsapp',
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
    curl_close($ch);

    $responseData = json_decode($response, true);

    if ($httpcode < 200 || $httpcode >= 300) {
        throw new Exception('WhatsApp API Error: ' . ($responseData['error']['message'] ?? $response));
    }

    // 4. If successful, save the agent's message to the database
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content) VALUES (?, 'agent', ?, ?)");
    $stmt->execute([$conversationId, $userId, $content]);

    // Update last message preview
    $stmt = $pdo->prepare("UPDATE conversations SET last_message_preview = ? WHERE id = ?");
    $stmt->execute([$content, $conversationId]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Message sent successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
