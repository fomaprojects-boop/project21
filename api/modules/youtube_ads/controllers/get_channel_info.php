<?php

// Paths are correct from here
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../models/YoutubeChannel.php'; // Correct Model call

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    // Notification 1: Authentication Error
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
    exit();
}

$tenantId = $_SESSION['user_id'];

// Use the existing PDO connection from db.php
global $pdo;

$youtubeChannelModel = new \Modules\YouTubeAds\Models\YoutubeChannel($pdo);

// Using the function 'getChannelByTenantId'
$channelInfo = $youtubeChannelModel->getChannelByTenantId($tenantId);

if ($channelInfo) {
    // --- KWA HAPA TUMERUDISHA UJUMBE WA PONGEZI BAADA YA KUCONNECT ---
    // Channel found. Send channel data PLUS the success message.
    echo json_encode([
        'status' => 'success',
        'channel' => [
            'channel_name' => $channelInfo['channel_name'],
            'thumbnail_url' => $channelInfo['thumbnail_url']
        ],
        // Notification 3: Attractive Success/Cheers Message
        'cheers_message' => '🎉 Connection Successful! Your channel is linked and ready to launch some great ads! 🚀'
    ]);
} else {
    // Channel not found. Send 'info' status with a welcoming English message
    // to prompt the frontend to display the attractive "Connect" interface.
    
    // Return 200 (OK) instead of 404
    http_response_code(200);
    
    echo json_encode([
        'status' => 'info', // Status for "not connected, but ready"
        // Notification 2: Attractive Welcome/Connect Message
        'message' => '👋 Welcome! Connect your YouTube Channel to start creating amazing YouTube ads.', 
        'action' => 'connect' // Action for the frontend to show the connect button
    ]);
}