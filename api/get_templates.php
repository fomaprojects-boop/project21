<?php
// api/get_templates.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$userId = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // Step 1: Adopt any "orphaned" templates created before the user_id column was added.
    // This is a one-time operation for each user who logs in and finds orphaned templates.
    $stmt_adopt = $pdo->prepare("UPDATE message_templates SET user_id = ? WHERE user_id IS NULL");
    $stmt_adopt->execute([$userId]);

    // Step 2: Fetch all templates belonging to the current user.
    $stmt_fetch = $pdo->prepare("SELECT id, name, body, header, footer, quick_replies, status, variables, category FROM message_templates WHERE user_id = ? ORDER BY name");
    $stmt_fetch->execute([$userId]);
    $templates = $stmt_fetch->fetchAll(PDO::FETCH_ASSOC);

    $pdo->commit();

    // Decode JSON fields (quick_replies and variables) into arrays
    foreach ($templates as &$template) {
        if (!empty($template['quick_replies'])) {
            $decoded_replies = json_decode($template['quick_replies']);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_replies)) {
                 $template['quick_replies'] = $decoded_replies;
            } else {
                // Fallback for comma-separated strings
                $template['quick_replies'] = array_map('trim', explode(',', $template['quick_replies']));
            }
        } else {
            $template['quick_replies'] = [];
        }

        if (!empty($template['variables'])) {
            $decoded_vars = json_decode($template['variables']);
            $template['variables'] = (json_last_error() === JSON_ERROR_NONE && is_array($decoded_vars)) ? $decoded_vars : [];
        } else {
            $template['variables'] = [];
        }
    }

    echo json_encode($templates);

} catch (PDOException $e) {
    // If something goes wrong, roll back the transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Database error in get_templates.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
?>
