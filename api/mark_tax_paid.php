<?php
// api/mark_tax_paid.php

require_once 'config.php';
require_once 'db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit();
}

$tax_type = $_POST['tax_type'] ?? ''; // 'VAT' or 'WHT'
$amount = $_POST['amount'] ?? 0;
$month = (int)date('m'); // Paying for current month (which is usually due next month)
$year = (int)date('Y');

if (!in_array($tax_type, ['VAT', 'WHT'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid tax type']);
    exit();
}

try {
    // Check if already exists
    $check_sql = "SELECT id FROM monthly_tax_status WHERE tax_type = ? AND month = ? AND year = ?";
    $stmt = $pdo->prepare($check_sql);
    $stmt->execute([$tax_type, $month, $year]);
    $existing = $stmt->fetch();

    if ($existing) {
        $sql = "UPDATE monthly_tax_status SET is_paid = 1, amount_paid = ?, date_paid = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$amount, $existing['id']]);
    } else {
        $sql = "INSERT INTO monthly_tax_status (tax_type, month, year, amount_paid, date_paid, is_paid) VALUES (?, ?, ?, ?, NOW(), 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tax_type, $month, $year, $amount]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Tax marked as paid successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
