<?php

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../models/Advertiser.php';

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

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

$advertiserModel = new \Modules\YouTubeAds\Models\Advertiser($db);
$data = $advertiserModel->findByTenant($_SESSION['user_id'], $page, $limit);

// --- REKEBISHO LIKO HAPA ---
// Tunatumia array_merge() kuunganisha 'status' na data yote ('advertisers', 'total', 'limit')
// ili JavaScript iweze kuzipata moja kwa moja.
echo json_encode(array_merge(['status' => 'success'], $data));