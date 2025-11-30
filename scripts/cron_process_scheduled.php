<?php
// Hii script inatakiwa ku-run via Cron Job
// Command: * * * * * cd /home/app.chatme.co.tz/public_html/scripts/ && /usr/bin/php cron_process_scheduled.php

// 1. Set Timezone iwe EAT (Muhimu kwa scheduled messages)
date_default_timezone_set('Africa/Dar_es_Salaam');

// 2. Logging Function (Ili kujua kinachoendelea)
function log_cron($msg) {
    $file = __DIR__ . '/cron_scheduled.log';
    $date = date('Y-m-d H:i:s');
    file_put_contents($file, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

// 3. Unganisha Database
// Tunatumia ../api/db.php kwasababu cron inarun ndani ya folder la scripts/
$dbPath = __DIR__ . '/../api/db.php';

if (!file_exists($dbPath)) {
    log_cron("CRITICAL ERROR: db.php haipatikani kwenye $dbPath");
    exit;
}

require_once $dbPath;

try {
    // 4. Set Database Timezone iwe +03:00
    // Hii inahakikisha NOW() ya SQL inalingana na saa ya Tanzania
    $pdo->exec("SET time_zone = '+03:00'");

    // 5. Tafuta messages ambazo muda wake umefika (scheduled_at <= SASA)
    // Tunachukua token na ID kutoka kwa User aliyepanga message
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

    if (empty($messages)) {
        // Hakuna message za kutuma, toka kimya kimya (usiujaze log file bure)
        exit;
    }

    log_cron("Found " . count($messages) . " scheduled messages due for sending.");

    foreach ($messages as $msg) {
        
        // 6. Phone Number Normalization (MUHIMU KWA SINGLE TICK FIX)
        // Safisha namba kabla ya kutuma Meta
        $phone = preg_replace('/[^0-9]/', '', $msg['recipient_phone']);
        
        // Case: 25507... -> 2557...
        if (substr($phone, 0, 4) === '2550') {
            $phone = '255' . substr($phone, 4);
        }
        // Case: 07... -> 2557...
        elseif (substr($phone, 0, 1) === '0') {
            $phone = '255' . substr($phone, 1);
        }
        // Case: 7... -> 2557...
        elseif (strlen($phone) === 9) {
            $phone = '255' . $phone;
        }

        // 7. Andaa Payload
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => $msg['message_type']
        ];

        if ($msg['message_type'] === 'text') {
            $payload['text'] = ['body' => $msg['content']];
        } 
        elseif ($msg['message_type'] === 'interactive') {
            $payload['interactive'] = is_string($msg['interactive_data']) ? json_decode($msg['interactive_data'], true) : $msg['interactive_data'];
        }
        elseif ($msg['message_type'] === 'template') {
            // Support for scheduled templates if needed
             $payload['template'] = is_string($msg['interactive_data']) ? json_decode($msg['interactive_data'], true) : $msg['interactive_data'];
        }

        // 8. Tuma kwa Meta (cURL)
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
        $curlError = curl_error($ch);
        curl_close($ch);

        // 9. Update Database kulingana na majibu
        if ($httpCode >= 200 && $httpCode < 300) {
            $responseDecoded = json_decode($response, true);
            $wamid = $responseDecoded['messages'][0]['id'] ?? null;

            // Update status to 'sent'
            $update = $pdo->prepare("UPDATE messages SET status = 'sent', provider_message_id = :wamid, sent_at = NOW() WHERE id = :id");
            $update->execute([':wamid' => $wamid, ':id' => $msg['message_id']]);
            
            log_cron("SUCCESS: Sent Msg ID {$msg['message_id']} to $phone");
        } else {
            // Log failure but don't delete, maybe mark failed or retry logic later
            log_cron("FAILED: Msg ID {$msg['message_id']} to $phone. Code: $httpCode. Error: $response");
        }
    }

} catch (Exception $e) {
    log_cron("SYSTEM ERROR: " . $e->getMessage());
}
?>