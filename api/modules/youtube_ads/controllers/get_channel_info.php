<?php

// Paths are correct from here
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../models/YoutubeChannel.php'; // Correct Model call

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
    exit();
}

$tenantId = $_SESSION['user_id'];
$specificChannelId = $_GET['id'] ?? null;

global $pdo;
$youtubeChannelModel = new \Modules\YouTubeAds\Models\YoutubeChannel($pdo);

// Multi-channel logic
$channelInfo = null;
if ($specificChannelId) {
    $channelInfo = $youtubeChannelModel->getChannelById($specificChannelId, $tenantId);
} else {
    // Default to first
    $channelInfo = $youtubeChannelModel->getChannelByTenantId($tenantId);
}

if ($channelInfo) {
    echo json_encode([
        'status' => 'success',
        'channel' => [
            'id' => $channelInfo['id'],
            'channel_name' => $channelInfo['channel_name'],
            'thumbnail_url' => $channelInfo['thumbnail_url']
        ],
        'cheers_message' => '🎉 Channel Active!'
    ]);
} else {
    http_response_code(200);
    echo json_encode([
        'status' => 'info',
        'message' => 'No active channel found. Please connect one.',
        'action' => 'connect'
    ]);
}
