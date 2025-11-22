<?php
require_once 'config.php';
require_once 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit;
}

$userId = $_SESSION['user_id'];

// Check if the financial year is closed
$date_for_check = $_POST['date'] ?? date('Y-m-d');
$year_for_check = date('Y', strtotime($date_for_check));
$stmt_check = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
$stmt_check->execute([$year_for_check]);
if ($stmt_check->fetchColumn()) {
    http_response_code(403); // Forbidden
    echo json_encode(['status' => 'error', 'message' => 'Cannot create new expenses in a closed financial year.']);
    exit();
}

$type = $_POST['type'] ?? 'claim'; // Default to 'claim' for safety
$expenseType = $_POST['expense_type'] ?? '';
$amount = $_POST['amount'] ?? 0;
$currency = $_POST['currency'] ?? 'TZS';
$date = $_POST['date'] ?? '';
$reference = $_POST['reference'] ?? null;
$isUrgent = isset($_POST['is_urgent']) && $_POST['is_urgent'] == '1' ? 1 : 0;
$attachmentUrl = null;

// Validation differs based on type
if ($type === 'claim') {
    $paymentMethod = $_POST['payment_method'] ?? '';
    if (empty($expenseType) || empty($amount) || empty($date) || empty($paymentMethod)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill all required fields for the claim.']);
        exit;
    }
} elseif ($type === 'requisition') {
    // <-- REKEBISHO LIKO HAPA
    // Tulibadilisha 'null' kuwa 'Pending' ili ikubaliane na sheria ya database (NOT NULL)
    // na kuchukua thamani iliyofichwa kutoka kwenye fomu.
    $paymentMethod = $_POST['payment_method'] ?? 'Pending';
    if (empty($expenseType) || empty($amount) || empty($reference)) {
        echo json_encode(['status' => 'error', 'message' => 'Please fill all required fields for the requisition.']);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid submission type.']);
    exit;
}


if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == 0) {
    $targetDir = "../uploads/receipts/";
    if (!file_exists($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    $fileName = uniqid() . '-' . basename($_FILES['attachment']['name']);
    $targetFilePath = $targetDir . $fileName;
    // Hii ilikuwa 'uploads/receipts/' sasa ni 'uploads/receipts/' ili iwe sawa na folder
    $attachmentUrl = "uploads/receipts/" . $fileName; 

    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFilePath)) {
        // File moved successfully
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to upload attachment.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Determine status based on type
    $status = ($type === 'requisition') ? 'Submitted' : 'Pending Approval';
    $comment = ($type === 'requisition') ? 'Requisition submitted' : 'Claim submitted';


    $stmt = $pdo->prepare(
        "INSERT INTO direct_expenses (user_id, expense_type, amount, currency, date, payment_method, reference, attachment_url, status, type, is_urgent, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([$userId, $expenseType, $amount, $currency, $date, $paymentMethod, $reference, $attachmentUrl, $status, $type, $isUrgent]);
    $expenseId = $pdo->lastInsertId();

    // Generate and save tracking number
    $trackingNumber = 'EXP-' . str_pad($expenseId, 6, '0', STR_PAD_LEFT);
    $stmt_track = $pdo->prepare("UPDATE direct_expenses SET tracking_number = ? WHERE id = ?");
    $stmt_track->execute([$trackingNumber, $expenseId]);

    // Log initial history
    $stmt = $pdo->prepare("INSERT INTO expense_approval_history (expense_id, user_id, status, comment) VALUES (?, ?, ?, ?)");
    $stmt->execute([$expenseId, $userId, $status, $comment]);

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => ucfirst($type) . ' submitted successfully.']);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>