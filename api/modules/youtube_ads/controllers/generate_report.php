<?php
namespace Modules\YouTubeAds\Controllers;

require_once __DIR__ . '/../../../../api/config.php';
require_once __DIR__ . '/../../../../api/db.php';
require_once __DIR__ . '/../models/AdReport.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';

use Modules\YouTubeAds\Models\AdReport;

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'User not authenticated.']);
    exit;
}

$tenantId = $_SESSION['user_id'];

// Get JSON Input
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid input data.']);
    exit;
}

$advertiserId = $data['advertiser_id'] ?? null;
$reportName = $data['report_name'] ?? 'Custom Report';
$videoIds = $data['video_ids'] ?? [];

if (!$advertiserId) {
    echo json_encode(['status' => 'error', 'message' => 'Advertiser ID is required.']);
    exit;
}

global $pdo;

try {
    // 1. Resolve Ad ID (Find any ad for this advertiser or default to 0/NULL if schema allows)
    // The AdReport model and table likely expect an ad_id as foreign key.
    // We try to find the most recent ad for this advertiser.
    $stmt = $pdo->prepare("SELECT id FROM ads WHERE advertiser_id = ? AND tenant_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$advertiserId, $tenantId]);
    $adId = $stmt->fetchColumn();

    if (!$adId) {
        // If no ad exists, we cannot link the report to an ad.
        // We check if we can insert 0. If there is a foreign key constraint, this might fail unless 0 is a valid ID or allowed.
        // Assuming strict FK, we might need to create a placeholder ad or handle this gracefully.
        // For now, let's try with 0 and catch exception if it fails.
        $adId = 0;
    }

    $analyticsData = [
        'report_name' => $reportName,
        'videos_count' => count($videoIds),
        'video_ids' => $videoIds,
        'generated_by' => $_SESSION['user_name'] ?? 'System'
    ];

    // Dummy PDF Generation
    $uploadDir = __DIR__ . '/../../../../uploads/reports/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $pdfFilename = 'report_' . uniqid() . '.pdf';
    $pdfPath = 'uploads/reports/' . $pdfFilename;
    $fullPdfPath = $uploadDir . $pdfFilename;

    // Use TCPDF if available, else simple text file renamed as PDF for mock
    if (class_exists('TCPDF')) {
        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->Write(0, "Report Name: $reportName\n");
        $pdf->Write(0, "Generated: " . date('Y-m-d H:i:s') . "\n");
        $pdf->Write(0, "Advertiser ID: $advertiserId\n");
        $pdf->Write(0, "Videos Included: " . count($videoIds) . "\n");
        $pdf->Output($fullPdfPath, 'F');
    } else {
        file_put_contents($fullPdfPath, "PDF Report: $reportName\nGenerated on " . date('Y-m-d H:i:s'));
    }

    $reportModel = new AdReport($pdo);
    // Note: AdReport::createReport signature: ($adId, $tenantId, $advertiserId, $reportDate, $analyticsData, $pdfPath)
    $reportModel->createReport($adId, $tenantId, $advertiserId, date('Y-m-d'), $analyticsData, $pdfPath);

    echo json_encode(['status' => 'success', 'message' => 'Report generated successfully.', 'pdf_url' => $pdfPath]);

} catch (\Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed: ' . $e->getMessage()]);
}
