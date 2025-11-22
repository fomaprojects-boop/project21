<?php

// This script is a DEBUG version of scheduler.php intended to be run from the BROWSER.
// It is location-agnostic and uses PDO for database connection.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Determine Paths dynamically
$currentDir = __DIR__;
$projectRoot = null;

// Helper to check if vendor exists in a path
function checkRoot($path) {
    return file_exists($path . '/vendor/autoload.php');
}

// Check common locations relative to this file
if (checkRoot($currentDir . '/..')) {
    // File is in api/
    $projectRoot = realpath($currentDir . '/..');
} elseif (checkRoot($currentDir . '/../../../..')) {
    // File is in api/modules/youtube_ads/jobs/
    $projectRoot = realpath($currentDir . '/../../../..');
} elseif (isset($_SERVER['DOCUMENT_ROOT']) && checkRoot($_SERVER['DOCUMENT_ROOT'])) {
    // Fallback to Document Root
    $projectRoot = $_SERVER['DOCUMENT_ROOT'];
}

if (!$projectRoot) {
    die("CRITICAL ERROR: Could not determine project root. vendor/autoload.php not found in any expected parent directories.<br>Current Dir: $currentDir");
}

$vendorAutoload = $projectRoot . '/vendor/autoload.php';
$logFile = $projectRoot . '/api/modules/youtube_ads/jobs/scheduler.log';

// Function for logging to browser
function log_message($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    // Still write to file
    @file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
    // Output to browser
    echo "[$timestamp] $message <br>" . PHP_EOL;
    flush();
}

echo "<h2>Scheduler Debug Run</h2>";
log_message("Debug Scheduler started.");
log_message("Project Root detected: $projectRoot");
log_message("Vendor Autoload found at: $vendorAutoload");

require_once $projectRoot . '/api/config.php';
require_once $projectRoot . '/api/db.php';
require_once $projectRoot . '/api/modules/youtube_ads/models/Ad.php';
require_once $projectRoot . '/api/modules/youtube_ads/services/ReportService.php';
require_once $projectRoot . '/api/modules/youtube_ads/services/UploadService.php';
require_once $projectRoot . '/api/modules/youtube_ads/models/AdVideoMap.php';
require_once $projectRoot . '/api/modules/youtube_ads/models/YoutubeToken.php';
require_once $projectRoot . '/api/modules/youtube_ads/services/EncryptionService.php';
require_once $vendorAutoload;

use Modules\YouTubeAds\Models\Ad;
use Modules\YouTubeAds\Models\AdVideoMap;
use Modules\YouTubeAds\Models\YoutubeToken;
use Modules\YouTubeAds\Services\ReportService;
use Modules\YouTubeAds\Services\UploadService;
use Google_Client;

log_message("Classes loaded. Initializing Scheduler...");

class Scheduler {
    private $db;
    private $adModel;
    private $reportService;
    private $adVideoMapModel;
    private $projectRoot;

    public function __construct($root) {
        $this->projectRoot = $root;
        log_message("Scheduler Constructor: Connecting to DB (PDO)...");
        
        try {
            // Use PDO for database connection
            $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8";
            $this->db = new \PDO($dsn, DB_USERNAME, DB_PASSWORD);
            $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            log_message("Database Connection failed: " . $e->getMessage());
            die("Connection failed: " . $e->getMessage());
        }

        log_message("Scheduler Constructor: Instantiating Ad Model...");
        $this->adModel = new Ad($this->db);
        
        log_message("Scheduler Constructor: Instantiating Report Service...");
        $this->reportService = new ReportService($this->db);
        
        log_message("Scheduler Constructor: Instantiating AdVideoMap Model...");
        $this->adVideoMapModel = new AdVideoMap($this->db);
        
        log_message("Scheduler Constructor: Done.");
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
                // Use project root to build path
                $filePath = $this->projectRoot . '/uploads/ads/' . $ad['file_path'];
                
                if (!file_exists($filePath)) {
                    log_message("File not found at: $filePath for ad ID: " . $ad['id']);
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
            $refreshToken = $client->getRefreshToken();
            
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

    // ... (Other methods are the same, we just needed the constructor fix)
    
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
        $this->db = null;
    }
}

try {
    $scheduler = new Scheduler($projectRoot);
    $scheduler->run();
} catch (\Exception $e) {
    log_message("CRITICAL EXCEPTION: " . $e->getMessage());
} catch (\Error $e) {
    log_message("CRITICAL ERROR: " . $e->getMessage());
}