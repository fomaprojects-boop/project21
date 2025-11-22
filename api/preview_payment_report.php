<?php
// api/preview_payment_report.php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment_report_generator.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

$payout_request_id = $_GET['id'] ?? null;

if (empty($payout_request_id)) {
    http_response_code(400);
    die('Payout ID is missing.');
}

try {
    // Generate and output the PDF to the browser
    generatePaymentReportPDF($pdo, $payout_request_id, 'I');
} catch (Exception $e) {
    http_response_code(500);
    error_log("Preview Payout Error: " . $e->getMessage());
    header('Content-Type: text/plain');
    die('Failed to generate preview: ' . $e->getMessage());
}
?>