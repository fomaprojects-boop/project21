<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conversation_id = $_POST['conversation_id'] ?? null;
if (!$conversation_id || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
    exit();
}

// File details
$file = $_FILES['file'];
$file_name = $file['name'];
$file_tmp = $file['tmp_name'];
$file_size = $file['size'];
$file_error = $file['error'];
$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

if ($file_error !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload error.']);
    exit();
}

// Move uploaded file to a public directory
$upload_dir = '../uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
$file_path = $upload_dir . uniqid() . '.' . $file_ext;
if (!move_uploaded_file($file_tmp, $file_path)) {
    echo json_encode(['success' => false, 'message' => 'Failed to store uploaded file.']);
    exit();
}

// Get file URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domain_name = $_SERVER['HTTP_HOST'];
$file_url = $protocol . $domain_name . dirname($_SERVER['SCRIPT_NAME'], 2) . '/uploads/' . basename($file_path);


// Get credentials and recipient
// (Logic copied and adapted from send_whatsapp_message.php)
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT whatsapp_access_token FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$access_token = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT c.phone_number FROM contacts c JOIN conversations conv ON c.id = conv.contact_id WHERE conv.id = ?");
$stmt->execute([$conversation_id]);
$recipient_phone = $stmt->fetchColumn();

if (!$access_token || !$recipient_phone) {
    echo json_encode(['success' => false, 'message' => 'API credentials or recipient not found.']);
    exit();
}

$stmt = $pdo->prepare("SELECT whatsapp_phone_number_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$phone_number_id = $stmt->fetchColumn();


// Determine message type based on extension
$image_types = ['jpg', 'jpeg', 'png', 'gif'];
$document_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

$type = 'document';
if (in_array($file_ext, $image_types)) {
    $type = 'image';
}

// Send via WhatsApp API
$url = "https://graph.facebook.com/v21.0/{$phone_number_id}/messages";
$payload = [
    'messaging_product' => 'whatsapp',
    'to' => $recipient_phone,
    'type' => $type,
    $type => [
        'link' => $file_url,
        'caption' => $file_name
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpcode >= 200 && $httpcode < 300) {
    // Save to DB
    $stmt = $pdo->prepare("INSERT INTO messages (conversation_id, sender_type, user_id, content, message_type) VALUES (?, 'agent', ?, ?, ?)");
    $stmt->execute([$conversation_id, $user_id, $file_name, $type]);
    echo json_encode(['success' => true]);
} else {
    unlink($file_path); // Clean up failed upload
    echo json_encode(['success' => false, 'message' => 'WhatsApp API Error: ' . $response]);
}
?>