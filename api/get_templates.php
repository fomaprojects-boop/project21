<?php
// api/get_templates.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

require_once 'db.php';

try {
    $stmt = $pdo->prepare("SELECT id, name, body, header, footer, quick_replies, status, variables FROM message_templates ORDER BY name");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Badilisha (decode) sehemu za JSON (kama quick_replies na variables) ziwe array
    foreach ($templates as &$template) {
        if (!empty($template['quick_replies'])) {
            // Hapa tunatarajia string iliyotenganishwa na koma, lakini database inaweza kuwa na JSON
            // Hebu tuangalie kama ni JSON kwanza
            $decoded_replies = json_decode($template['quick_replies']);
            if (json_last_error() === JSON_ERROR_NONE) {
                 $template['quick_replies'] = $decoded_replies;
            } else {
                // Kama si JSON, chukulia ni string iliyotenganishwa na koma
                $template['quick_replies'] = array_map('trim', explode(',', $template['quick_replies']));
            }
        } else {
            $template['quick_replies'] = [];
        }

        if (!empty($template['variables'])) {
            $template['variables'] = json_decode($template['variables']);
        } else {
            $template['variables'] = [];
        }
    }

    echo json_encode($templates);

} catch (PDOException $e) {
    error_log('Database error in get_templates.php: ' . $e->getMessage());
    echo json_encode([]);
}
?>

