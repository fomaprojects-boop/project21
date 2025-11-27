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
$status_filter = $_GET['status'] ?? null;

try {
    $pdo->beginTransaction();

    // Step 1: Adopt any "orphaned" templates created before the user_id column was added.
    $stmt_adopt = $pdo->prepare("UPDATE message_templates SET user_id = ? WHERE user_id IS NULL");
    $stmt_adopt->execute([$userId]);

    // Step 2: Fetch templates
    $sql = "SELECT id, name, body, header, footer, quick_replies, status, variables, category FROM message_templates WHERE user_id = ?";
    $params = [$userId];

    if ($status_filter) {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }

    $sql .= " ORDER BY name";

    $stmt_fetch = $pdo->prepare($sql);
    $stmt_fetch->execute($params);
    $templates = $stmt_fetch->fetchAll(PDO::FETCH_ASSOC);

    $pdo->commit();

    // Decode JSON fields
    foreach ($templates as &$template) {
        if (!empty($template['quick_replies'])) {
            $decoded_replies = json_decode($template['quick_replies']);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_replies)) {
                 $template['quick_replies'] = $decoded_replies;
            } else {
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
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Database error in get_templates.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
?>
