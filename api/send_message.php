<?php
// api/send_message.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$conversation_id = $data['conversation_id'] ?? null;
$content = $data['content'] ?? '';
$user_id = $_SESSION['user_id'];

if (empty($conversation_id) || empty($content)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing conversation ID or content.']);
    exit();
}

// --- TAARIFA ZAKO MUHIMU KUTOKA META ---
$access_token = 'EAAR4o4uKvuIBP42bZC0M03cRCmKMqcTy6xr5HG2DZAVg1Q778ID1XrDHurIC1zZAM6sxYvjcIYLP3wfZBD2vOB9MBoQ5ZAGPrHGfBGjdu1FxjwSf5MeQ6oE5WSZAVBrL4XHD63pZAHGVXih50nDpV5ynTA4Iv9hzo1Cv0ImrPpGsj0tO6hZAE1xsBKRO0BQx4wkOktZCTtRVJvlsyEid4UOnOAg1yy5kPUaywtj4diPCxQQiPipoZD';
$phone_number_id = '893668633819206';
// -----------------------------------------

$pdo->beginTransaction();

try {
    // 1. Pata namba ya simu ya mteja kutoka kwenye conversation_id
    $stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
    $stmt->execute([$conversation_id]);
    $contact = $stmt->fetch();

    if (!$contact) {
        throw new Exception("Contact not found for this conversation.");
    }
    $recipient_phone_number = $contact['phone_number'];

    // 2. Tuma ujumbe kupitia WhatsApp Cloud API
    $url = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
    $message_data = [
        'messaging_product' => 'whatsapp',
        'to' => $recipient_phone_number,
        'type' => 'text',
        'text' => ['body' => $content]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        throw new Exception("Failed to send message via WhatsApp API. Response: " . $response);
    }

    // 3. Hifadhi ujumbe uliotumwa kwenye database yako
    $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content) VALUES (?, 'agent', ?, ?)");
    $stmt->execute([$conversation_id, $user_id, $content]);

    // 4. Sasisha (update) 'last_message_preview' kwenye jedwali la conversations
    $stmt = $pdo->prepare("UPDATE conversations SET last_message_preview = ? WHERE id = ?");
    $stmt->execute([$content, $conversation_id]);

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Message sent successfully.']);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Send Message Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>