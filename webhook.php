<?php
// webhook.php

// Andika rekodi ya majaribio yote ya GET
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_REQUEST['hub_mode'])) {
    file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " --- Verification Attempt ---\n" . print_r($_REQUEST, true) . "\n\n", FILE_APPEND);
}

// 1. Uthibitisho wa Webhook (Webhook Verification)
$verify_token = 'ChatMeToken2025'; 
if (isset($_REQUEST['hub_mode']) && $_REQUEST['hub_mode'] == 'subscribe' && isset($_REQUEST['hub_verify_token']) && $_REQUEST['hub_verify_token'] == $verify_token) {
    echo $_REQUEST['hub_challenge'];
    exit;
}

// 2. Kupokea Ujumbe Unaotumwa na WhatsApp
require_once 'api/db.php'; 

$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Andika data iliyopokelewa kwenye faili kwa ajili ya uchunguzi (debugging)
file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " --- POST Request Received ---\n" . $input . "\n\n", FILE_APPEND);

if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
    $message_data = $data['entry'][0]['changes'][0]['value']['messages'][0];
    
    if ($message_data['type'] != 'text') {
        http_response_code(200); 
        exit;
    }

    $from_number = $message_data['from']; 
    $message_body = $message_data['text']['body']; 
    $contact_name = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name']; 

    try {
        $pdo->beginTransaction();

        // Hatua A: Tafuta au Tengeneza Anwani (Contact)
        $stmt = $pdo->prepare("SELECT id FROM contacts WHERE phone_number = ?");
        $stmt->execute([$from_number]);
        $contact = $stmt->fetch();

        if (!$contact) {
            $stmt = $pdo->prepare("INSERT INTO contacts (name, phone_number) VALUES (?, ?)");
            $stmt->execute([$contact_name, $from_number]);
            $contact_id = $pdo->lastInsertId();
        } else {
            $contact_id = $contact['id'];
        }

        // Hatua B: Tafuta au Tengeneza Mazungumzo (Conversation)
        $stmt = $pdo->prepare("SELECT id FROM conversations WHERE contact_id = ?");
        $stmt->execute([$contact_id]);
        $conversation = $stmt->fetch();

        if (!$conversation) {
            $stmt = $pdo->prepare("INSERT INTO conversations (contact_id, last_message_preview) VALUES (?, ?)");
            $stmt->execute([$contact_id, $message_body]);
            $conversation_id = $pdo->lastInsertId();
        } else {
            $conversation_id = $conversation['id'];
            $stmt = $pdo->prepare("UPDATE conversations SET last_message_preview = ? WHERE id = ?");
            $stmt->execute([$message_body, $conversation_id]);
        }

        // Hatua C: Hifadhi Ujumbe Mpya
        $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, content) VALUES (?, 'contact', ?)");
        $stmt->execute([$conversation_id, $message_body]);

        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        file_put_contents('webhook_error.txt', date('Y-m-d H:i:s') . ' - Error: ' . $e->getMessage() . "\n\n", FILE_APPEND);
    }
}

http_response_code(200);
?>