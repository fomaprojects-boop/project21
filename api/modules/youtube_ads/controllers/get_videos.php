<?php

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../services/YouTubeService.php';

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
    exit();
}

$youtubeService = new \Modules\YouTubeAds\Services\YouTubeService();
$videos = $youtubeService->getChannelVideos($_SESSION['user_id']);

echo json_encode($videos);
