<?php
// This script should be run via Cron every minute
// * * * * * php /path/to/scripts/cron_process_scheduled.php

require_once dirname(__DIR__) . '/api/db.php';
// We might need to load config to get Facebook credentials if they are in config.php
// But normally they are in `users` table or similar. `send_whatsapp_message.php` logic handles this.
// Since we can't easily reuse the HTTP endpoint logic in CLI without `curl`, we'll replicate the sending logic
// or, simpler, use `file_get_contents` to call the local API if we knew the URL.
// Given we are in CLI, it's better to replicate logic or include the file if possible.
// But `send_whatsapp_message.php` relies on `$_POST` and `$_SESSION`.
// So we should probably refactor `send_whatsapp_message.php` to have a function we can call.
// For now, I will implement the logic directly here to fetch and send.

try {
    // 1. Find due messages
    $stmt = $pdo->prepare("
        SELECT m.*, c.whatsapp_phone_number_id, u.facebook_access_token
        FROM messages m
        JOIN conversations conv ON m.conversation_id = conv.id
        JOIN contacts c ON conv.contact_id = c.id -- Wait, phone number ID is usually the SENDER (Tenant), not Contact.
        -- Contact phone is in `contacts`.
        -- Tenant credentials are in `users`. We need to link conversation to user/tenant.
        -- `messages` has `user_id`. Let's use that.
        JOIN users u ON m.user_id = u.id
        WHERE m.status = 'scheduled'
        AND m.scheduled_at <= NOW()
        AND m.is_internal = 0
    ");

    // Correction: We need the *recipient's* phone number from `contacts` and the *sender's* credentials from `users`.
    // Let's refine the query.
    $stmt = $pdo->prepare("
        SELECT
            m.id as message_id,
            m.content,
            m.message_type,
            m.interactive_data,
            con.phone_number as recipient_phone,
            u.whatsapp_phone_number_id,
            u.whatsapp_access_token
        FROM messages m
        JOIN conversations cv ON m.conversation_id = cv.id
        JOIN contacts con ON cv.contact_id = con.id
        JOIN users u ON m.user_id = u.id
        WHERE m.status = 'scheduled'
        AND m.scheduled_at <= NOW()
    ");

    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as $msg) {
        // Construct Payload
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $msg['recipient_phone'],
        ];

        if ($msg['message_type'] === 'text') {
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $msg['content']];
        } elseif ($msg['message_type'] === 'interactive' && !empty($msg['interactive_data'])) {
            $payload['type'] = 'interactive';
            $payload['interactive'] = json_decode($msg['interactive_data'], true);
        } else {
            // Fallback or other types
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $msg['content']];
        }

        // Send to Meta
        $url = "https://graph.facebook.com/v21.0/" . $msg['whatsapp_phone_number_id'] . "/messages";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $msg['whatsapp_access_token'],
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $responseDecoded = json_decode($response, true);
            $wamid = $responseDecoded['messages'][0]['id'] ?? null;

            // Update DB
            $update = $pdo->prepare("UPDATE messages SET status = 'sent', provider_message_id = :wamid, sent_at = NOW() WHERE id = :id");
            $update->execute([':wamid' => $wamid, ':id' => $msg['message_id']]);
            echo "Sent message {$msg['message_id']}\n";
        } else {
            // Mark as failed or retry?
            echo "Failed to send message {$msg['message_id']}: $response\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
