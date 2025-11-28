<?php

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
// This file seems to be a duplicate of get_ad_reports.php,
// but I will correct the paths for consistency.
// In a real refactoring, this file might be deleted.

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
    exit();
}

$db = new \mysqli(\DB_SERVER, \DB_USERNAME, \DB_PASSWORD, \DB_NAME);
if ($db->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit();
}

// Assuming it should fetch ad_reports, similar to get_ad_reports.php
require_once __DIR__ . '/../models/AdReport.php';
$adReportModel = new \Modules\YouTubeAds\Models\AdReport($db);
$result = $adReportModel->getByTenant($_SESSION['user_id']);


echo json_encode(['status' => 'success', 'reports' => $result['data'], 'total' => $result['total']]);
