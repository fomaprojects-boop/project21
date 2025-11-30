<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../models/YoutubeChannel.php';

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$tenantId = $_SESSION['user_id'];
// If we are impersonating, session user_id is the tenant admin, which is correct.

global $pdo;
$model = new \Modules\YouTubeAds\Models\YoutubeChannel($pdo);

try {
    $channels = $model->getAllChannelsByTenantId($tenantId);
    echo json_encode(['status' => 'success', 'channels' => $channels]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
