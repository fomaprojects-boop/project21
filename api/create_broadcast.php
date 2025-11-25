<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please log in again.']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

// --- Data Validation ---
$campaign_name = trim($data['campaign_name'] ?? '');
$message_type = $data['message_type'] ?? 'custom';
$message_body = trim($data['message_body'] ?? '');
$template_id = $data['template_id'] ?? null;
$schedule_type = $data['schedule_type'] ?? 'now';
$scheduled_at_string = $data['scheduled_at'] ?? '';
$selected_contacts = $data['selected_contacts'] ?? [];
$recipient_type = $data['recipient_type'] ?? 'all';

if (empty($campaign_name)) {
    echo json_encode(['status' => 'error', 'message' => 'Campaign Name is required.']);
    exit();
}

if ($message_type === 'custom' && empty($message_body)) {
    echo json_encode(['status' => 'error', 'message' => 'Message body is required for a custom message.']);
    exit();
}

if ($message_type === 'template' && empty($template_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a template.']);
    exit();
}

// --- Schedule Handling ---
if ($schedule_type === 'later' && !empty($scheduled_at_string)) {
    try {
        $scheduled_at = new DateTime($scheduled_at_string);
        $scheduled_at_mysql = $scheduled_at->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid schedule date format.']);
        exit();
    }
} else {
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $scheduled_at_mysql = $now->format('Y-m-d H:i:s');
}

// --- Credential Fetching (User > Global) ---
$userId = $_SESSION['user_id'];
$whatsappToken = null;
$whatsappPhoneId = null;

try {
    $stmt = $pdo->prepare("SELECT whatsapp_access_token, whatsapp_phone_number_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userSettings) {
        $whatsappToken = $userSettings['whatsapp_access_token'] ?? null;
        $whatsappPhoneId = $userSettings['whatsapp_phone_number_id'] ?? null;
    }
} catch (PDOException $e) { /* Ignore */ }

if (empty($whatsappToken) || empty($whatsappPhoneId)) {
    try {
        $stmt = $pdo->prepare("SELECT whatsapp_token, whatsapp_phone_id FROM settings LIMIT 1");
        $stmt->execute();
        $globalSettings = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($globalSettings) {
            $whatsappToken = $globalSettings['whatsapp_token'] ?? null;
            $whatsappPhoneId = $globalSettings['whatsapp_phone_id'] ?? null;
        }
    } catch (PDOException $e) { /* Ignore */ }
}

// Only strictly require credentials if sending NOW
if ($schedule_type === 'now' && (empty($whatsappToken) || empty($whatsappPhoneId))) {
    echo json_encode(['status' => 'error', 'message' => 'WhatsApp is not connected. Please go to Settings > Channels to connect.']);
    exit();
}

// --- Determine Recipients ---
$recipients = [];
try {
    if ($recipient_type === 'select' && !empty($selected_contacts)) {
        // Ensure IDs are integers
        $ids = array_map('intval', $selected_contacts);
        $in = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id, phone_number, name FROM contacts WHERE id IN ($in)");
        $stmt->execute($ids);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fetch all contacts
        $stmt = $pdo->query("SELECT id, phone_number, name FROM contacts");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error fetching contacts: ' . $e->getMessage()]);
    exit();
}

if (empty($recipients)) {
    echo json_encode(['status' => 'error', 'message' => 'No valid recipients found.']);
    exit();
}

// --- Database Insertion (Broadcast Job) ---
try {
    $pdo->beginTransaction();

    // Create the broadcast record
    $status = ($schedule_type === 'now') ? 'Sending...' : 'Scheduled';
    $stmt = $pdo->prepare(
        "INSERT INTO broadcasts (campaign_name, message_body, template_id, status, scheduled_at)
         VALUES (?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $campaign_name,
        ($message_type === 'custom') ? $message_body : null,
        ($message_type === 'template') ? $template_id : null,
        $status,
        $scheduled_at_mysql
    ]);
    $broadcast_id = $pdo->lastInsertId();

    // If "Send Now", execute the sending loop immediately
    if ($schedule_type === 'now') {
        $successCount = 0;
        $failCount = 0;
        $lastError = '';

        // Helper function for sending
        function sendToWhatsApp($phone, $messageType, $bodyOrTemplateId, $token, $phoneId, $templateData = null) {
            $url = "https://graph.facebook.com/v21.0/{$phoneId}/messages";
            $data = [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
            ];

            if ($messageType === 'custom') {
                $data['type'] = 'text';
                $data['text'] = ['body' => $bodyOrTemplateId];
            } else {
                $data['type'] = 'template';
                $data['template'] = [
                    'name' => $templateData['name'], // You'll need to fetch template name by ID
                    'language' => ['code' => 'en_US'] // Assumption, logic should ideally handle language
                ];
                // If template has variables, they would need to be handled here.
                // For simplicity in this demo, we assume simple templates or static ones.
                // A real implementation needs a complex variable mapper.
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['code' => $code, 'response' => json_decode($res, true)];
        }

        // Fetch template name if needed
        $templateName = '';
        if ($message_type === 'template') {
            $tStmt = $pdo->prepare("SELECT name FROM message_templates WHERE id = ?");
            $tStmt->execute([$template_id]);
            $tpl = $tStmt->fetch(PDO::FETCH_ASSOC);
            $templateName = $tpl['name'] ?? '';
            if (!$templateName) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Template not found in database.']);
                exit();
            }
        }

        foreach ($recipients as $contact) {
            // Sanitize phone number: Remove +, spaces, dashes, parentheses
            $rawPhone = $contact['phone_number'];
            $sanitizedPhone = preg_replace('/[^0-9]/', '', $rawPhone);

            // Smart Normalization for TZ (255)
            // Converts 0755... -> 255755...
            if (substr($sanitizedPhone, 0, 1) === '0') {
                $sanitizedPhone = '255' . substr($sanitizedPhone, 1);
            } elseif (strlen($sanitizedPhone) === 9) {
                // Handle case where leading 0 is missing but no country code (e.g., 755...)
                $sanitizedPhone = '255' . $sanitizedPhone;
            }

            $sendRes = sendToWhatsApp(
                $sanitizedPhone,
                $message_type,
                ($message_type === 'custom') ? $message_body : $template_id,
                $whatsappToken,
                $whatsappPhoneId,
                ($message_type === 'template') ? ['name' => $templateName] : null
            );

            if ($sendRes['code'] >= 200 && $sendRes['code'] < 300) {
                $successCount++;
                // Optional: Log message to messages table
            } else {
                $failCount++;
                $lastError = $sendRes['response']['error']['message'] ?? 'Unknown API error';
            }
        }

        $finalStatus = ($failCount === 0) ? 'Sent' : (($successCount > 0) ? 'Partial' : 'Failed');
        $updateStmt = $pdo->prepare("UPDATE broadcasts SET status = ? WHERE id = ?");
        $updateStmt->execute([$finalStatus, $broadcast_id]);

        $pdo->commit();

        if ($finalStatus === 'Failed') {
            echo json_encode(['status' => 'error', 'message' => "Failed to send broadcast. Error: $lastError"]);
        } elseif ($finalStatus === 'Partial') {
            echo json_encode(['status' => 'warning', 'message' => "Broadcast sent with some errors. Sent: $successCount, Failed: $failCount. Last Error: $lastError"]);
        } else {
            echo json_encode(['status' => 'success', 'message' => "Broadcast sent successfully to $successCount recipient(s)."]);
        }

    } else {
        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => "Broadcast scheduled successfully."]);
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
