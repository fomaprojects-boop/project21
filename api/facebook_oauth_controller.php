<?php
session_start();
require_once 'db.php';
require_once 'config.php';

function display_error($message) {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md text-center max-w-md">
        <h1 class="text-2xl font-bold text-red-600 mb-4">Connection Failed</h1>
        <p class="text-gray-700 mb-6">{$message}</p>
        <a href="../index.php#settings" class="bg-indigo-600 text-white font-semibold px-5 py-2.5 rounded-lg hover:bg-indigo-700">
            Go Back to Settings
        </a>
    </div>
</body>
</html>
HTML;
    exit;
}

function make_curl_request($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local testing; consider removing in production if you have proper SSL setup
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        display_error('cURL Error: ' . curl_error($ch) . '. This is a server connectivity issue. Please contact your hosting provider.');
    }
    curl_close($ch);
    return $response;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$action = $_GET['action'] ?? '';
// Use BASE_URL from config.php to ensure consistency
// Use App credentials from the config file
$app_id = defined('FACEBOOK_APP_ID') ? FACEBOOK_APP_ID : '';
$app_secret = defined('FACEBOOK_APP_SECRET') ? FACEBOOK_APP_SECRET : '';

if ($action === 'embedded_signup') {
    // This new action handles the token sent from the JavaScript SDK
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $short_lived_token = $input['accessToken'] ?? '';

    if (empty($short_lived_token)) {
        echo json_encode(['status' => 'error', 'message' => 'Access token not provided.']);
        exit;
    }

    // Exchange short-lived token for a long-lived token
    $long_lived_url = "https://graph.facebook.com/v19.0/oauth/access_token?grant_type=fb_exchange_token&client_id={$app_id}&client_secret={$app_secret}&fb_exchange_token={$short_lived_token}";
    $response = make_curl_request($long_lived_url);
    $long_lived_data = json_decode($response, true);
    if (empty($long_lived_data['access_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to get long-lived access token. Error: ' . htmlspecialchars($long_lived_data['error']['message'] ?? 'Unknown error')]);
        exit;
    }
    $long_lived_token = $long_lived_data['access_token'];

    // Now, with the long-lived token, get the debug info to find shared WABAs
    $debug_url = "https://graph.facebook.com/v19.0/debug_token?input_token={$long_lived_token}&access_token={$long_lived_token}";
    $debug_response = make_curl_request($debug_url);
    $debug_data = json_decode($debug_response, true);

    if (empty($debug_data['data']['granular_scopes'])) {
        echo json_encode(['status' => 'error', 'message' => 'Could not retrieve account scopes. Please try again.']);
        exit;
    }

    // Find the WhatsApp Business Account ID from the scopes
    $waba_id = null;
    foreach ($debug_data['data']['granular_scopes'] as $scope) {
        if ($scope['scope'] === 'whatsapp_business_management') {
            if (!empty($scope['target_ids'][0])) {
                $waba_id = $scope['target_ids'][0];
                break;
            }
        }
    }

    if (!$waba_id) {
        echo json_encode(['status' => 'error', 'message' => 'No WhatsApp Business Account was found. Please ensure you completed all steps in the popup.']);
        exit;
    }

    // Get Phone Number ID using the WABA ID
    $phone_numbers_url = "https://graph.facebook.com/v19.0/{$waba_id}/phone_numbers?access_token={$long_lived_token}";
    $phone_response = make_curl_request($phone_numbers_url);
    $phone_numbers_data = json_decode($phone_response, true);

    if (empty($phone_numbers_data['data'][0]['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'No phone number is registered with your WhatsApp Business Account.']);
        exit;
    }
    $phone_number_id = $phone_numbers_data['data'][0]['id'];

    // Save the credentials
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("UPDATE users SET whatsapp_phone_number_id = ?, whatsapp_business_account_id = ?, whatsapp_access_token = ? WHERE id = ?");
    if ($stmt->execute([$phone_number_id, $waba_id, $long_lived_token, $user_id])) {
        echo json_encode(['status' => 'success', 'message' => 'WhatsApp account connected successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save credentials to the database.']);
    }
    exit;

}  elseif ($action === 'disconnect') {
    // Clear WhatsApp credentials from the specific user's record in the users table
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("UPDATE users SET whatsapp_phone_number_id = NULL, whatsapp_business_account_id = NULL, whatsapp_access_token = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
    header('Location: ../index.php#settings');
    exit;

} else {
    display_error('An invalid action was requested. Please start over.');
}
