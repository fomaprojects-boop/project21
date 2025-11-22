<?php

namespace Modules\YouTubeAds\Jobs;

// Since this job is instantiated by the scheduler, we assume paths are relative to the API root.
require_once __DIR__ . '/../services/AdInsertionService.php';
require_once __DIR__ . '/../services/UploadService.php';
require_once __DIR__ . '/../models/Ad.php';
require_once __DIR__ . '/../models/AdVideoMap.php';
require_once __DIR__ . '/../services/YouTubeService.php';
require_once __DIR__ . '/../services/VideoDownloadService.php';

use Modules\YouTubeAds\Services\AdInsertionService;
use Modules\YouTubeAds\Services\UploadService;
use Modules\YouTubeAds\Models\Ad;
use Modules\YouTubeAds\Models\AdVideoMap;
use Modules\YouTubeAds\Services\YouTubeService;
use Modules\YouTubeAds\Services\VideoDownloadService;

class InsertAdJob {
    private $adId;
    private $db;

    public function __construct($adId, $db) {
        $this->adId = $adId;
        $this->db = $db;
    }

    public function handle() {
        $adModel = new Ad($this->db);
        $adVideoMapModel = new AdVideoMap($this->db);
        $youtubeService = new YouTubeService();
        $adInsertionService = new AdInsertionService();
        $videoDownloadService = new VideoDownloadService();

        $ad = $adModel->findById($this->adId);
        if (!$ad) {
            error_log("InsertAdJob: Ad with ID {$this->adId} not found.");
            return;
        }

        $tenantId = $ad['tenant_id'];
        $client = $youtubeService->getValidAccessToken($tenantId);
        if (!$client) {
            error_log("InsertAdJob: Could not get valid token for tenant ID: {$tenantId}");
            return;
        }

        $uploadService = new UploadService($client);
        $adVideoMaps = $adVideoMapModel->findByAd($this->adId);

        foreach ($adVideoMaps as $map) {
            if ($map['approved'] && !$map['inserted']) {
                $mergedVideoPath = null;
                try {
                    $newVideoIdOrPath = $adInsertionService->insertAd($map['video_id'], $ad['file_path'], $ad['placement'], $youtubeService);

                    if (!$newVideoIdOrPath) {
                        error_log("InsertAdJob: Ad insertion failed for ad ID {$this->adId}.");
                        continue;
                    }
                    
                    if (strpos($newVideoIdOrPath, 'editor_api_') === 0) {
                        $newVideoId = $newVideoIdOrPath;
                        $adVideoMapModel->updateStatus($map['id'], 1, $newVideoId);
                        error_log("InsertAdJob: Successfully processed ad with Editor API for new video {$newVideoId}");
                    } else {
                        $mergedVideoPath = $newVideoIdOrPath;
                        $videoMetadata = [
                            'title' => 'New Video with Ad: ' . $ad['title'],
                            'description' => 'This video includes a promotion. Original video ID: ' . $map['video_id'],
                            'tags' => [],
                            'privacyStatus' => 'unlisted',
                        ];
                        $uploadedVideoId = $uploadService->upload($mergedVideoPath, $videoMetadata);

                        if ($uploadedVideoId) {
                            $adVideoMapModel->updateStatus($map['id'], 1, $uploadedVideoId);
                            error_log("InsertAdJob: Successfully uploaded merged video {$uploadedVideoId}");
                        } else {
                            error_log("InsertAdJob: Upload failed for merged video.");
                        }
                    }

                } catch (\Exception $e) {
                    error_log("InsertAdJob: Exception occurred: " . $e->getMessage());
                } finally {
                    if ($mergedVideoPath && file_exists($mergedVideoPath)) {
                        unlink($mergedVideoPath);
                    }
                }
            }
        }
    }
}
