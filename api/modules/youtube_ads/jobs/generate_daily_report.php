<?php

// Robust way to get the project root directory
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(__DIR__, 4));
}

require_once PROJECT_ROOT . '/vendor/autoload.php';
require_once PROJECT_ROOT . '/api/config.php';
require_once PROJECT_ROOT . '/api/db.php';
require_once PROJECT_ROOT . '/api/mailer_config.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/models/Ad.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/models/Advertiser.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/models/AdReport.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/models/YoutubeToken.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/services/ReportService.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/services/YouTubeService.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/services/EmailService.php';
require_once PROJECT_ROOT . '/api/modules/youtube_ads/services/EncryptionService.php';

use Modules\YouTubeAds\Models\Ad;
use Modules\YouTubeAds\Services\ReportService;

echo "Cron Job: Starting daily ad performance report generation...\n";

try {
    // Establish DB connection (PDO)
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8";
    $pdo = new \PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}

$adModel = new Ad($pdo);
$reportService = new ReportService($pdo);

$activeCampaigns = $adModel->getAllActiveCampaigns();

if (empty($activeCampaigns)) {
    echo "No active campaigns found. Exiting.\n";
    exit;
}

echo "Found " . count($activeCampaigns) . " active campaigns to report on.\n";

foreach ($activeCampaigns as $campaign) {
    $ad_id = $campaign['id'];
    echo "Processing report for Ad ID: $ad_id\n";
    
    try {
        // Use the unified ReportService to generate PDF, save to DB, and email
        $reportService->generateAndEmailReport($ad_id);
        echo "Report generated and processed for Ad ID: $ad_id\n";
    } catch (\Exception $e) {
        echo "Error processing Ad ID $ad_id: " . $e->getMessage() . "\n";
    }
    echo "----------------------------------------\n";
}

echo "Cron Job: Daily report generation complete.\n";