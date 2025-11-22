<?php

// This script is intended to be run from the command line (CLI) via a cron job.
// It needs to establish its own path context.
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

// Function for logging
function log_message($message) {
    $logFile = __DIR__ . '/scheduler.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
    echo "[$timestamp] $message" . PHP_EOL;
}

log_message("Scheduler started.");

// Set the working directory to the API root
chdir(__DIR__ . '/../../../');

// Verify critical files exist
$vendorAutoload = __DIR__ . '/../../../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    log_message("CRITICAL ERROR: vendor/autoload.php not found at $vendorAutoload");
    exit(1);
}

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../models/Ad.php';
require_once __DIR__ . '/../services/ReportService.php';
require_once __DIR__ . '/../services/UploadService.php';
require_once __DIR__ . '/../models/AdVideoMap.php';
require_once __DIR__ . '/../models/YoutubeToken.php';
require_once __DIR__ . '/../services/EncryptionService.php';
require_once $vendorAutoload;

use Modules\YouTubeAds\Models\Ad;
use Modules\YouTubeAds\Models\AdVideoMap;
use Modules\YouTubeAds\Models\YoutubeToken;
use Modules\YouTubeAds\Services\ReportService;
use Modules\YouTubeAds\Services\UploadService;
use Google_Client;

class Scheduler {
    private $db;
    private $adModel;
    private $reportService;
    private $adVideoMapModel;

    public function __construct() {
        $this->db = new \mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($this->db->connect_error) {
            log_message("Database Connection failed: " . $this->db->connect_error);
            die("Connection failed: " . $this->db->connect_error);
        }
        $this->adModel = new Ad($this->db);
        $this->reportService = new ReportService($this->db);
        $this->adVideoMapModel = new AdVideoMap($this->db);
    }

    public function run() {
        log_message("Scheduler running...");
        $this->processQueuedUploads();
        $this->processActiveAds();
        $this->processExpiredAds();
        $this->generatePeriodicReports();
        log_message("Scheduler finished.");
    }

    private function processQueuedUploads() {
        log_message("Checking for queued uploads...");
        try {
            $queuedAds = $this->adModel->findQueuedForUpload();
        } catch (\Exception $e) {
            log_message("Error fetching queued ads: " . $e->getMessage());
            return;
        }

        if (empty($queuedAds)) {
            log_message("No ads in upload queue.");
            return;
        }

        foreach ($queuedAds as $ad) {
            log_message("Processing ad ID: " . $ad['id']);
            
            try {
                $googleClient = $this->getAuthenticatedGoogleClient($ad['tenant_id']);
                if (!$googleClient) {
                    log_message("Could not get authenticated Google client for tenant ID: " . $ad['tenant_id'] . ". Skipping.");
                    continue;
                }

                $uploadService = new UploadService($googleClient);
                $filePath = __DIR__ . '/../../../../uploads/ads/' . $ad['file_path'];
                
                if (!file_exists($filePath)) {
                    log_message("File not found at: $filePath for ad ID: " . $ad['id']);
                    // Optionally update status to 'Error' or similar
                    continue;
                }

                log_message("Starting upload for file: $filePath");
                $youtubeVideoId = $uploadService->upload($filePath, $ad['title'], "Ad for " . $ad['title'], ['ad', 'sponsorship'], 'unlisted');

                if ($youtubeVideoId) {
                    $this->adVideoMapModel->create($ad['id'], $ad['tenant_id'], $youtubeVideoId);
                    $this->adModel->updateStatus($ad['id'], 'active');
                    log_message("Ad ID " . $ad['id'] . " uploaded successfully. Video ID: $youtubeVideoId");
                } else {
                    log_message("Failed to upload video for ad ID: " . $ad['id']);
                }
            } catch (\Exception $e) {
                log_message("Exception processing ad ID " . $ad['id'] . ": " . $e->getMessage());
            }
        }
    }

    private function getAuthenticatedGoogleClient($tenantId) {
        $youtubeTokenModel = new YoutubeToken($this->db, new \Modules\YouTubeAds\Services\EncryptionService());
        $tokenData = $youtubeTokenModel->getTokens($tenantId);

        if (!$tokenData) {
            log_message("No tokens found for tenant ID: $tenantId");
            return null;
        }

        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setAccessToken($tokenData);

        if ($client->isAccessTokenExpired()) {
            log_message("Access token expired for tenant ID: $tenantId. Refreshing...");
            $refreshToken = $client->getRefreshToken(); // This gets it from the setAccessToken array if present
            
            if (!$refreshToken && isset($tokenData['refresh_token'])) {
                 $refreshToken = $tokenData['refresh_token'];
            }

            if ($refreshToken) {
                try {
                    $client->fetchAccessTokenWithRefreshToken($refreshToken);
                    $newAccessToken = $client->getAccessToken();
                    
                    if (isset($newAccessToken['error'])) {
                         log_message("Error refreshing token: " . json_encode($newAccessToken));
                         return null;
                    }

                    $expires_at = new \DateTime();
                    // Default to 3600 if not set
                    $expiresIn = $newAccessToken['expires_in'] ?? 3599;
                    $expires_at->add(new \DateInterval('PT' . $expiresIn . 'S'));
                    
                    $youtubeTokenModel->saveTokens(
                        $tenantId,
                        $newAccessToken['access_token'],
                        $newAccessToken['refresh_token'] ?? $refreshToken,
                        $expires_at
                    );
                    log_message("Token refreshed successfully.");
                } catch (\Exception $e) {
                    log_message("Exception refreshing token: " . $e->getMessage());
                    return null;
                }
            } else {
                log_message("No refresh token available for tenant ID: $tenantId");
                return null;
            }
        }

        return $client;
    }

    private function processActiveAds() {
        // Placeholder
    }

    private function processExpiredAds() {
        log_message("Processing expired ads...");
        $adsToRemove = $this->adModel->findAdsToRemove();
        if (empty($adsToRemove)) {
            log_message("No ads to remove.");
            return;
        }
        
        foreach ($adsToRemove as $ad) {
            log_message("Deactivating ad ID: " . $ad['id']);
            $this->adModel->updateStatus($ad['id'], 'inactive');
        }
    }

    private function generatePeriodicReports() {
        log_message("Generating periodic reports...");
        $activeAds = $this->adModel->findActiveAds();
        if (empty($activeAds)) {
            log_message("No active ads for reporting.");
            return;
        }

        foreach ($activeAds as $ad) {
            log_message("Generating report for ad ID: " . $ad['id']);
            try {
                $this->reportService->generateAndEmailReport($ad['id']);
            } catch (\Exception $e) {
                log_message("Error generating report for ad ID " . $ad['id'] . ": " . $e->getMessage());
            }
        }
    }

    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}

$scheduler = new Scheduler();
$scheduler->run();
