<?php

namespace Modules\YouTubeAds\Controllers;

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
            'prompt' => 'consent' // Force consent to ensure we get a refresh token
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

            // --- MULTI-CHANNEL UPDATE ---
            // Instead of overwriting a single token for the user, we persist it with the channel info.

            // 1. Get Access Token
            $accessToken = $token_data['access_token'];
            $refreshToken = $token_data['refresh_token'] ?? null; // Null if re-authing without consent prompt? Need to handle.

            // 2. Fetch Channel Info DIRECTLY using the new token to identify WHO this is
            // We pass the raw token data or just the access token string?
            // Existing youtubeService->getChannelInfo likely relied on stored tokens.
            // We need a way to pass this specific token.
            // Let's modify fetchAndStoreChannelInfo to accept the token data directly.

            $channel_info = $this->fetchAndStoreChannelInfo($tenantId, $token_data);

            if ($channel_info) {
                // Success! Redirect to dashboard with success flag
                header('Location: ' . BASE_URL . '/index.php?youtube_connected=true&at=true#youtube-ads');
                exit();
            } else {
                $this->sendErrorResponse('Failed to Get Channel', 'Successfully connected to Google, but failed to retrieve your YouTube channel information.');
                exit();
            }

        } catch (\Google\Service\Exception $e) {
            $error = json_decode($e->getMessage(), true);
            $message = $error['error']['message'] ?? 'An unknown Google API error occurred.';
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

    private function fetchAndStoreChannelInfo($tenantId, $token_data) {
        // We bypass the stored token and use the fresh one to identify the channel
        // youtubeService needs a method to fetch info given a raw token array
        // If not exists, we might need to instantiate Google Client manually here or extend service.
        // For simplicity, assuming existing service can take a manual token or we refactor it slightly.
        // Actually, looking at previous file content, getChannelInfo accepted $new_access_token array.

        $channelInfo = $this->youtubeService->getChannelInfo($tenantId, $token_data);

        if ($channelInfo) {
            // Save Channel + Tokens in one go (Multi-channel logic)
            $this->youtubeChannelModel->saveChannelInfo(
                $channelInfo['id'],
                $tenantId,
                $channelInfo['name'],
                $channelInfo['thumbnail'],
                $token_data['access_token'],
                $token_data['refresh_token'] ?? null,
                $tenantId // Added by user ID (tenant admin)
            );

            // Note: We are no longer using YoutubeToken model to store a single user token.
            // Tokens are now part of the channel row.
        }
        return $channelInfo;
    }

    private function sendErrorResponse($title, $message) {
        http_response_code(500);
        $redirectUrl = BASE_URL . '/index.php#youtube-ads';
        echo "<h1>$title</h1><p>$message</p><a href='$redirectUrl'>Return</a>";
        exit();
    }
}

$authController = new AuthController();
$authController->handleRequest();
