<?php
// api/upload_receipt.php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$response = ['status' => 'error', 'message' => 'An unknown error occurred.'];

try {
    // Tunapokea ID ya ombi na faili
    $payout_request_id = $_POST['payout_request_id'] ?? null;
    $receipt_file = $_FILES['receipt_file'] ?? null;

    if (empty($payout_request_id) || empty($receipt_file) || $receipt_file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Payout ID and a valid receipt file are required.');
    }

    // Hakikisha ombi lipo na limeidhinishwa (Approved)
    $stmt = $pdo->prepare("SELECT id FROM payout_requests WHERE id = ? AND status = 'Approved'");
    $stmt->execute([$payout_request_id]);
    $request = $stmt->fetch();

    if (!$request) {
        throw new Exception('This payout request is not in an "Approved" state. Cannot upload receipt.');
    }

    // Shughulikia Upakiaji wa Faili
    $upload_dir = __DIR__ . '/../uploads/receipts/'; // Nenda kwenye folda tuliyotengeneza
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            throw new Exception('Failed to create upload directory. Check permissions.');
        }
    }

    $file_extension = strtolower(pathinfo($receipt_file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
    
    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception('Invalid file type. Only PDF, JPG, and PNG are allowed.');
    }
    if ($receipt_file['size'] > 5000000) { // 5MB Limit
        throw new Exception('File is too large. Maximum 5MB allowed.');
    }

    // Tengeneza jina jipya na la kipekee
    $new_filename = 'receipt_' . $payout_request_id . '_' . time() . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;

    if (!move_uploaded_file($receipt_file['tmp_name'], $upload_path)) {
        throw new Exception('Failed to upload receipt file. Check server permissions.');
    }
    
    $file_url = 'uploads/receipts/' . $new_filename; // URL ya kuhifadhi kwenye database

    // Sasisha (Update) Database na linki ya risiti
    $stmt = $pdo->prepare("UPDATE payout_requests SET payment_receipt_url = ? WHERE id = ?");
    $stmt->execute([$file_url, $payout_request_id]);

    $response = ['status' => 'success', 'message' => 'Payment receipt uploaded successfully!', 'receipt_url' => $file_url];

} catch (Exception $e) {
    $response = ['status' => 'error', 'message' => $e->getMessage()];
    http_response_code(400); // Bad Request
}

echo json_encode($response);
?>
