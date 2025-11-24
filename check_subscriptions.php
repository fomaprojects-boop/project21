<?php
// check_subscriptions.php
// Diagnostic tool to verify Meta Webhook Subscriptions

require_once 'api/config.php';

// Constants
$appId = defined('FACEBOOK_APP_ID') ? FACEBOOK_APP_ID : '';
$appSecret = defined('FACEBOOK_APP_SECRET') ? FACEBOOK_APP_SECRET : '';

if (empty($appId) || empty($appSecret)) {
    die("Error: FACEBOOK_APP_ID or FACEBOOK_APP_SECRET is missing in api/config.php.\n");
}

// Generate App Access Token (Client Credentials Flow)
$accessToken = $appId . '|' . $appSecret;

echo "--- Checking Subscriptions for App ID: $appId ---\n";

$url = "https://graph.facebook.com/v21.0/{$appId}/subscriptions?access_token=" . $accessToken;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Relaxed for diag
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    die("cURL Error: $error\n");
}

if ($httpCode !== 200) {
    echo "HTTP Error: $httpCode\n";
    echo "Response: $response\n";
    exit;
}

$data = json_decode($response, true);

if (empty($data['data'])) {
    echo "No active subscriptions found.\n";
    echo "Action Required: Go to Meta Dashboard > Webhooks and subscribe to 'messages'.\n";
} else {
    foreach ($data['data'] as $sub) {
        echo "\nObject: " . $sub['object'] . "\n";
        echo "Callback URL: " . $sub['callback_url'] . "\n";
        echo "Active: " . ($sub['active'] ? 'Yes' : 'No') . "\n";
        echo "Fields:\n";
        if (isset($sub['fields']) && is_array($sub['fields'])) {
            foreach ($sub['fields'] as $field) {
                $marker = ($field['name'] === 'messages') ? " [CRITICAL]" : "";
                echo " - " . $field['name'] . "$marker\n";
            }

            // Check for 'messages' specifically
            $fieldNames = array_column($sub['fields'], 'name');
            if (!in_array('messages', $fieldNames)) {
                echo "\n[WARNING] 'messages' field is MISSING from the subscription list!\n";
                echo "This explains why you receive empty payloads.\n";
            } else {
                echo "\n[OK] 'messages' field is subscribed.\n";
            }
        } else {
            echo " - No fields found.\n";
        }
    }
}
?>
