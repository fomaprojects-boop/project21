<?php
// api/submit_default_templates.php
require_once 'db.php';
require_once 'config.php';
require_once 'get_facebook_credentials.php';

// This function will be called internally, so no session or authentication here.
// It relies on being passed a valid user ID.

function submit_all_default_templates_for_user($userId) {
    global $pdo;

    try {
        // Fetch the user's default templates that are still pending submission
        $stmt_templates = $pdo->prepare(
            "SELECT id, name, category, body, quick_replies
             FROM message_templates
             WHERE user_id = ? AND status = 'PENDING' AND meta_template_id IS NULL"
        );
        $stmt_templates->execute([$userId]);
        $defaultTemplates = $stmt_templates->fetchAll(PDO::FETCH_ASSOC);

        if (empty($defaultTemplates)) {
            error_log("No default templates to submit for user ID: $userId");
            return; // Nothing to do
        }

        // Get user's Meta credentials
        $credentials = getFacebookCredentials($userId);
        $waba_id = $credentials['waba_id'];
        $access_token = $credentials['access_token'];

        foreach ($defaultTemplates as $template) {
            // Prepare payload for Meta
            $components = [
                ['type' => 'BODY', 'text' => $template['body']]
            ];

            if (!empty($template['quick_replies'])) {
                $buttons = [];
                foreach (explode(',', $template['quick_replies']) as $reply) {
                    if(trim($reply) !== '') {
                        $buttons[] = ['type' => 'QUICK_REPLY', 'text' => trim($reply)];
                    }
                }
                if (!empty($buttons)) {
                    $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
                }
            }

            $payload = [
                'name' => strtolower($template['name'] . '_' . time()), // Add timestamp for uniqueness
                'language' => 'en_US',
                'category' => $template['category'],
                'components' => $components
            ];

            $url = "https://graph.facebook.com/v21.0/{$waba_id}/message_templates";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$access_token}",
                "Content-Type: application/json"
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code >= 200 && $http_code < 300) {
                $meta_response = json_decode($response, true);
                if (isset($meta_response['id'])) {
                    // Update the local template with Meta's ID and name
                    $update_stmt = $pdo->prepare(
                        "UPDATE message_templates
                         SET meta_template_id = ?, meta_template_name = ?, status = 'PENDING_APPROVAL'
                         WHERE id = ?"
                    );
                    $update_stmt->execute([
                        $meta_response['id'],
                        $payload['name'],
                        $template['id']
                    ]);
                }
            } else {
                $error_response = json_decode($response, true);
                $errorMessage = $error_response['error']['message'] ?? 'Unknown Meta API error';
                error_log("Failed to submit template {$template['name']} for user {$userId}: {$errorMessage}");
                // We'll log the error but continue to try the next templates
            }
        }
    } catch (Exception $e) {
        error_log("Error in submit_all_default_templates_for_user: " . $e->getMessage());
    }
}
?>
