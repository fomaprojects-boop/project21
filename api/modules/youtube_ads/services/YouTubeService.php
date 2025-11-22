<?php

namespace Modules\YouTubeAds\Services;

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';

use Google_Client;
use Google_Service_YouTube;
use Modules\YouTubeAds\Models\YoutubeToken;

class YouTubeService {
    private $db;
    private $youtubeTokenModel;

    public function __construct($db, YoutubeToken $youtubeTokenModel) {
        $this->db = $db;
        $this->youtubeTokenModel = $youtubeTokenModel;
    }
    
    public function getChannelInfo($tenantId, $new_access_token = null) {
        $client = $this->getAuthenticatedClient($tenantId, $new_access_token);
        if (!$client) return null;

        $youtube = new Google_Service_YouTube($client);
        $channels = $youtube->channels->listChannels('snippet', ['mine' => true]);
        
        if (empty($channels->getItems())) return null;

        $channel = $channels->getItems()[0];
        return [
            'id' => $channel->getId(),
            'name' => $channel->getSnippet()->getTitle(),
            'thumbnail' => $channel->getSnippet()->getThumbnails()->getDefault()->getUrl()
        ];
    }

    public function fetchVideoAnalytics($videoIds, $tenantId) {
        $client = $this->getAuthenticatedClient($tenantId);
        if (!$client) {
            return [];
        }

        $youtube = new Google_Service_YouTube($client);
        
        // videoIds is an array of IDs.
        // We can pass comma-separated string up to 50 IDs.
        if (!is_array($videoIds)) {
            $videoIds = [$videoIds];
        }

        $chunks = array_chunk($videoIds, 50);
        $analytics = [];

        foreach ($chunks as $chunk) {
            $idsString = implode(',', $chunk);
            try {
                $response = $youtube->videos->listVideos('statistics', ['id' => $idsString]);
                
                foreach ($response->getItems() as $item) {
                    $stats = $item->getStatistics();
                    $analytics[] = [
                        'video_id' => $item->getId(),
                        'views' => (int)$stats->getViewCount(),
                        'likes' => (int)$stats->getLikeCount(),
                        'comments' => (int)$stats->getCommentCount(),
                        'estimatedMinutesWatched' => 0 // Not available in basic Data API
                    ];
                }
            } catch (\Exception $e) {
                error_log("YouTubeService: Failed to fetch stats for videos: " . $e->getMessage());
            }
        }

        return ['analytics' => $analytics];
    }
    
    private function getAuthenticatedClient($tenantId, $new_access_token = null) {
        $tokenData = $this->youtubeTokenModel->getTokens($tenantId);

        // If a new access token is provided, use it directly
        if ($new_access_token) {
            $tokenData = array_merge($tokenData ?: [], $new_access_token);
        }

        if (!$tokenData || !isset($tokenData['access_token'])) {
            error_log("YouTubeService: No access token found for tenant {$tenantId}.");
            return null;
        }

        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setAccessToken($tokenData);

        if ($client->isAccessTokenExpired()) {
            if (empty($tokenData['refresh_token'])) {
                error_log("YouTubeService: Access token expired, but no refresh token available for tenant {$tenantId}.");
                return null;
            }
            
            $client->fetchAccessTokenWithRefreshToken($tokenData['refresh_token']);
            $newAccessToken = $client->getAccessToken();
            
            $expires_at = new \DateTime();
            $expires_at->add(new \DateInterval('PT' . $newAccessToken['expires_in'] . 'S'));
            
            $this->youtubeTokenModel->saveTokens(
                $tenantId,
                $newAccessToken['access_token'],
                $newAccessToken['refresh_token'] ?? $tokenData['refresh_token'], // Keep old refresh token if new one is not provided
                $expires_at
            );
        }

        return $client;
    }
}