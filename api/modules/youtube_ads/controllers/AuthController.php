<?php

namespace Modules\YouTubeAds\Controllers;

// Corrected paths: Go up three levels from /api/modules/youtube_ads/controllers/ to /api/
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once __DIR__ . '/../models/YoutubeToken.php';
require_once __DIR__ . '/../models/YoutubeChannel.php';
require_once __DIR__ . '/../services/YouTubeService.php';

use Modules\YouTubeAds\Services\EncryptionService;
use Modules\YouTubeAds\Models\YoutubeToken;
use Modules\YouTubeAds\Models\YoutubeChannel;
use Modules\YouTubeAds\Services\YouTubeService;

class AuthController {
    private $db;
    private $encryptionService;
    private $youtubeTokenModel;
    private $youtubeChannelModel;
    private $youtubeService;

    private $googleClientId;
    private $googleClientSecret;
    private $googleRedirectUri;

    public function __construct() {
        global $pdo;
        $this->db = $pdo;
        $this->encryptionService = new EncryptionService();
        $this->youtubeTokenModel = new YoutubeToken($this->db, $this->encryptionService);
        $this->youtubeChannelModel = new YoutubeChannel($this->db);
        $this->youtubeService = new YouTubeService($this->db, $this->youtubeTokenModel);

        $this->googleClientId = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : getenv('GOOGLE_CLIENT_ID');
        $this->googleClientSecret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : getenv('GOOGLE_CLIENT_SECRET');
        $this->googleRedirectUri = defined('GOOGLE_REDIRECT_URI') ? GOOGLE_REDIRECT_URI : getenv('GOOGLE_REDIRECT_URI');
    }

    public function handleRequest() {
        if (isset($_GET['code'])) {
            $this->handleGoogleCallback();
        } else {
            $this->redirectToGoogleAuth();
        }
    }

    private function redirectToGoogleAuth() {
        $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->googleClientId,
            'redirect_uri' => $this->googleRedirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly https://www.googleapis.com/auth/youtube.upload',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ]);
        header('Location: ' . $auth_url);
        exit();
    }

    private function handleGoogleCallback() {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            if (!isset($_SESSION['user_id'])) {
                $this->sendErrorResponse('User Not Authenticated', 'Please log in to the system before connecting your YouTube account.');
                exit();
            }
            $tenantId = $_SESSION['user_id'];

            if (empty($_GET['code'])) {
                $this->sendErrorResponse('Authentication Error', 'Authorization code not found. Please try again.');
                exit();
            }

            $code = $_GET['code'];
            $token_data = $this->exchangeCodeForTokens($code);

            if (isset($token_data['error'])) {
                $errorMessage = $token_data['error_description'] ?? $token_data['error'];
                $this->sendErrorResponse('Token Exchange Error', 'Failed to get token from Google: ' . htmlspecialchars($errorMessage));
                exit();
            }

            // Store tokens
            $this->storeTokens($token_data, $tenantId);
            
            // Fetch and store channel info using the NEW access token
            $channel_info = $this->fetchAndStoreChannelInfo($tenantId, $token_data);

            if ($channel_info) {
                header('Location: ' . BASE_URL . '/index.php?youtube_connected=true#youtube-ads');
                exit();
            } else {
                $this->sendErrorResponse('Failed to Get Channel', 'Successfully connected to Google, but failed to retrieve your YouTube channel information.');
                exit();
            }

        } catch (\Google\Service\Exception $e) {
            $error = json_decode($e->getMessage(), true);
            $message = $error['error']['message'] ?? 'An unknown Google API error occurred.';
            
            if ($e->getCode() == 403 && strpos($message, 'has not been used') !== false) {
                $message = 'The YouTube API is not enabled. Please contact the system administrator to enable "YouTube Data API v3" in the Google Cloud Console.';
            }

            $this->sendErrorResponse('Google API Error (Code: ' . $e->getCode() . ')', $message);
        
        } catch (\Exception $e) {
            $this->sendErrorResponse('System Error (Internal Error)', $e->getMessage());
        }
    }

    private function exchangeCodeForTokens($code) {
        $token_url = 'https://oauth2.googleapis.com/token';
        
        $post_data = [
            'code' => $code,
            'client_id' => $this->googleClientId,
            'client_secret' => $this->googleClientSecret,
            'redirect_uri' => $this->googleRedirectUri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $token_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    private function storeTokens($token_data, $tenantId) {
        $expires_at = new \DateTime();
        $expires_at->add(new \DateInterval('PT' . $token_data['expires_in'] . 'S'));
        $this->youtubeTokenModel->saveTokens(
            $tenantId,
            $token_data['access_token'],
            $token_data['refresh_token'] ?? null,
            $expires_at
        );
    }

    private function fetchAndStoreChannelInfo($tenantId, $new_access_token = null) {
        $channelInfo = $this->youtubeService->getChannelInfo($tenantId, $new_access_token);

        if ($channelInfo) {
            $this->youtubeChannelModel->saveChannelInfo(
                $channelInfo['id'],
                $tenantId,
                $channelInfo['name'],
                $channelInfo['thumbnail']
            );
        }
        return $channelInfo;
    }

    private function sendErrorResponse($title, $message) {
        http_response_code(500);
        $redirectUrl = BASE_URL . '/index.php#youtube-ads';
        
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>An Error Occurred</title>
    <style>
        body { font-family: sans-serif; background-color: #f1f5f9; color: #333; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .error-container { background-color: #fff; border-radius: 12px; padding: 32px; max-width: 600px; width: 100%; text-align: center; border-top: 5px solid #ef4444; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        .error-title { font-size: 24px; font-weight: 600; color: #1e293b; margin-bottom: 12px; }
        .error-message { font-size: 16px; color: #475569; margin-bottom: 24px; line-height: 1.6; }
        .error-button { display: inline-block; background-color: #3b82f6; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; }
        .error-button:hover { background-color: #2563eb; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1 class="error-title">{$title}</h1>
        <p class="error-message">{$message}</p>
        <a href="{$redirectUrl}" class="error-button">Return to Dashboard</a>
    </div>
</body>
</html>
HTML;
        exit();
    }
}

$authController = new AuthController();
$authController->handleRequest();
