<?php
// api/get_payroll_batch_details.php
// Endpoint to fetch details for a specific payroll batch
require_once 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$batch_id = $_GET['id'] ?? null;

if (!$batch_id) {
    echo json_encode(['status' => 'error', 'message' => 'Batch ID is required.']);
    exit;
}

try {
    // Pata maelezo ya 'batch'
    $stmt_batch = $pdo->prepare("SELECT * FROM payroll_batches WHERE id = ?");
    $stmt_batch->execute([$batch_id]);
    $batch_details = $stmt_batch->fetch(PDO::FETCH_ASSOC);

    if (!$batch_details) {
        throw new Exception("Batch not found.");
    }

    // Pata maelezo ya 'entries'
    $stmt_entries = $pdo->prepare("SELECT * FROM payroll_entries WHERE batch_id = ?");
    $stmt_entries->execute([$batch_id]);
    $entries = $stmt_entries->fetchAll(PDO::FETCH_ASSOC);

    $batch_details['entries'] = $entries;

    echo json_encode(['status' => 'success', 'data' => $batch_details]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
