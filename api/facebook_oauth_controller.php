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
$app_id = defined('FACEBOOK_APP_ID') ? FACEBOOK_APP_ID : '';
$app_secret = defined('FACEBOOK_APP_SECRET') ? FACEBOOK_APP_SECRET : '';

if ($action === 'embedded_signup') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $code = $input['code'] ?? '';

    if (empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Authorization code not provided.']);
        exit;
    }

    $token_url = "https://graph.facebook.com/v21.0/oauth/access_token?client_id={$app_id}&client_secret={$app_secret}&redirect_uri=&code={$code}";
    $response = make_curl_request($token_url);
    $token_data = json_decode($response, true);

    if (empty($token_data['access_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to exchange code for access token. Error: ' . htmlspecialchars($token_data['error']['message'] ?? 'Unknown error')]);
        exit;
    }
    $long_lived_token = $token_data['access_token'];

    $app_access_token = $app_id . '|' . $app_secret;
    $debug_url = "https://graph.facebook.com/v21.0/debug_token?input_token={$long_lived_token}&access_token={$app_access_token}";
    $debug_response = make_curl_request($debug_url);
    $debug_data = json_decode($debug_response, true);

    if (empty($debug_data['data']['granular_scopes'])) {
        echo json_encode(['status' => 'error', 'message' => 'Could not retrieve account scopes.']);
        exit;
    }

    $waba_id = null;
    foreach ($debug_data['data']['granular_scopes'] as $scope) {
        if ($scope['scope'] === 'whatsapp_business_management' && !empty($scope['target_ids'][0])) {
            $waba_id = $scope['target_ids'][0];
            break;
        }
    }

    if (!$waba_id) {
        echo json_encode(['status' => 'error', 'message' => 'No WhatsApp Business Account was found.']);
        exit;
    }

    $phone_numbers_url = "https://graph.facebook.com/v21.0/{$waba_id}/phone_numbers?access_token={$long_lived_token}";
    $phone_response = make_curl_request($phone_numbers_url);
    $phone_numbers_data = json_decode($phone_response, true);

    if (empty($phone_numbers_data['data'][0]['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'No phone number is registered with your WABA.']);
        exit;
    }
    $phone_number_id = $phone_numbers_data['data'][0]['id'];

    // Save Credentials to both users and settings tables
    $user_id = $_SESSION['user_id'];

    $stmt_user = $pdo->prepare("UPDATE users SET whatsapp_phone_number_id = ?, whatsapp_business_account_id = ?, whatsapp_access_token = ?, whatsapp_status = 'Connected' WHERE id = ?");
    $stmt_settings = $pdo->prepare("UPDATE settings SET whatsapp_phone_number_id = ?, whatsapp_business_account_id = ?, whatsapp_access_token = ? WHERE id = 1");

    $pdo->beginTransaction();
    try {
        $stmt_user->execute([$phone_number_id, $waba_id, $long_lived_token, $user_id]);
        $stmt_settings->execute([$phone_number_id, $waba_id, $long_lived_token]);
        $pdo->commit();

        $_SESSION['whatsapp_phone_number_id'] = $phone_number_id;
        $_SESSION['whatsapp_access_token'] = $long_lived_token;
        $_SESSION['whatsapp_business_account_id'] = $waba_id;
        $_SESSION['whatsapp_status'] = 'Connected';

        echo json_encode(['status' => 'success', 'message' => 'WhatsApp account connected successfully.']);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;

} elseif ($action === 'disconnect') {
    $user_id = $_SESSION['user_id'];

    $stmt_user = $pdo->prepare("UPDATE users SET whatsapp_phone_number_id = NULL, whatsapp_business_account_id = NULL, whatsapp_access_token = NULL, whatsapp_status = 'Disconnected' WHERE id = ?");
    $stmt_settings = $pdo->prepare("UPDATE settings SET whatsapp_phone_number_id = NULL, whatsapp_business_account_id = NULL, whatsapp_access_token = NULL WHERE id = 1");

    $pdo->beginTransaction();
    try {
        $stmt_user->execute([$user_id]);
        $stmt_settings->execute();
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        // Log the error but don't block the user
        error_log("Failed to clear DB on disconnect: " . $e->getMessage());
    }

    unset($_SESSION['whatsapp_phone_number_id']);
    unset($_SESSION['whatsapp_access_token']);
    unset($_SESSION['whatsapp_business_account_id']);
    $_SESSION['whatsapp_status'] = 'Disconnected';

    // Redirect back to settings page in the main app
    header('Location: ../index.php#settings');
    exit;

} else {
    display_error('An invalid action was requested.');
}
?>
