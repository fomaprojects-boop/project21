<?php

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Logging Configuration
$logFile = __DIR__ . '/daily_report.log';

use Modules\YouTubeAds\Models\Ad;
use Modules\YouTubeAds\Services\ReportService;

function log_message($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] $message";

    // Append to log file
    @file_put_contents($logFile, $formatted . PHP_EOL, FILE_APPEND);

    // Output to screen (CLI or Browser)
    if (php_sapi_name() === 'cli') {
        echo $formatted . PHP_EOL;
    } else {
        echo $formatted . "<br>";
        flush(); // Force output to browser
    }
}

log_message("Process Started: Daily Ad Performance Report Generation");

// Robust Project Root Detection
// Current path: api/modules/youtube_ads/jobs/generate_daily_report.php
// Root is 4 levels up: api/modules/youtube_ads/jobs/ -> api/modules/youtube_ads/ -> api/modules/ -> api/ -> ROOT
if (!defined('PROJECT_ROOT')) {
    $calculatedRoot = dirname(__DIR__, 4);
    if (!file_exists($calculatedRoot . '/vendor/autoload.php')) {
        // Fallback: Check if we are in public_html structure
        if (isset($_SERVER['DOCUMENT_ROOT']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php')) {
            $calculatedRoot = $_SERVER['DOCUMENT_ROOT'];
        } else {
            log_message("CRITICAL ERROR: Could not find vendor/autoload.php at calculated root: $calculatedRoot");
            exit(1);
        }
    }
    define('PROJECT_ROOT', $calculatedRoot);
}

log_message("Project Root detected: " . PROJECT_ROOT);

try {
    // Load Dependencies
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

    // Establish DB connection (PDO)
    log_message("Connecting to database...");
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8";
    $pdo = new \PDO($dsn, DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    log_message("Database connected successfully.");

    $adModel = new Ad($pdo);
    $reportService = new ReportService($pdo);

    $activeCampaigns = $adModel->getAllActiveCampaigns();

    if (empty($activeCampaigns)) {
        log_message("No active campaigns found. Exiting.");
        exit;
    }

    log_message("Found " . count($activeCampaigns) . " active campaigns to report on.");

    foreach ($activeCampaigns as $campaign) {
        $ad_id = $campaign['id'];
        log_message("Processing report for Ad ID: $ad_id");

        try {
            // Use the unified ReportService to generate PDF, save to DB, and email
            $reportService->generateAndEmailReport($ad_id);
            log_message("SUCCESS: Report generated and processed for Ad ID: $ad_id");
        } catch (\Exception $e) {
            log_message("ERROR processing Ad ID $ad_id: " . $e->getMessage());
        }
        log_message("----------------------------------------");
    }

    log_message("Process Completed: Daily report generation finished.");

} catch (\Throwable $e) {
    log_message("FATAL EXCEPTION: " . $e->getMessage());
    log_message("Stack Trace: " . $e->getTraceAsString());
    exit(1);
}
