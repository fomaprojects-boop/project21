<?php
session_start();
require_once 'db.php';
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Define the default PIN (6-digit)
// Ideally this should be configurable, but for this task we use a safe default
// or allow the user to pass it in the body.
$DEFAULT_PIN = '123456';

$input = json_decode(file_get_contents('php://input'), true);
$pin = $input['pin'] ?? $DEFAULT_PIN;

// Validate PIN (must be 6 digits)
if (!preg_match('/^\d{6}$/', $pin)) {
    echo json_encode(['status' => 'error', 'message' => 'PIN must be exactly 6 digits.']);
    exit;
}

try {
    // 1. Fetch user credentials from DB
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT whatsapp_phone_number_id, whatsapp_access_token FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['whatsapp_phone_number_id']) || empty($user['whatsapp_access_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'No WhatsApp number linked to this account. Please connect first.']);
        exit;
    }

    $phone_number_id = $user['whatsapp_phone_number_id'];
    $access_token = $user['whatsapp_access_token'];

    // 2. Prepare API Request
    // Endpoint: POST https://graph.facebook.com/v21.0/{phone_number_id}/register
    // Using v21.0 as requested by the user
    $url = "https://graph.facebook.com/v21.0/{$phone_number_id}/register";

    $payload = [
        'messaging_product' => 'whatsapp',
        'pin' => $pin
    ];

    // 3. Execute Request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    curl_close($ch);

    // 4. Handle Response
    $result = json_decode($response, true);

    // Success check: either success=true OR explicit success in meta payload
    if ($http_code === 200 && isset($result['success']) && $result['success']) {

        // Update DB status to Connected
        $updateStmt = $pdo->prepare("UPDATE users SET whatsapp_status = 'Connected' WHERE id = ?");
        $updateStmt->execute([$user_id]);

        echo json_encode([
            'status' => 'success',
            'message' => 'WhatsApp number registered successfully! Status is now Connected.',
            'data' => $result
        ]);
    } else {
        // Check for specific error messages
        $error_msg = $result['error']['message'] ?? 'Unknown error from Meta API';
        // Often if already registered, it returns success: true or a specific error.
        // If it says "already registered", we can consider it a success or info.
        echo json_encode([
            'status' => 'error',
            'message' => 'Registration failed: ' . $error_msg,
            'details' => $result
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>