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

$tax_type = $_POST['tax_type'] ?? ''; // 'VAT', 'WHT', or 'Stamp Duty'
$amount = $_POST['amount'] ?? 0;

if (!in_array($tax_type, ['VAT', 'WHT', 'Stamp Duty'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid tax type']);
    exit();
}

try {
    $current_month = (int)date('m');
    $current_year = (int)date('Y');

    // Calculate Previous Month
    $prev_month = $current_month - 1;
    $prev_year = $current_year;
    if ($prev_month == 0) {
        $prev_month = 12;
        $prev_year = $current_year - 1;
    }

    // Smart Logic: Check if Previous Month is unpaid. If so, pay that (Liability).
    // Otherwise, pay Current Month (Accruing/Early).

    $check_sql = "SELECT id, is_paid FROM monthly_tax_status WHERE tax_type = ? AND month = ? AND year = ?";
    $stmt = $pdo->prepare($check_sql);
    $stmt->execute([$tax_type, $prev_month, $prev_year]);
    $prev_record = $stmt->fetch();

    if (!$prev_record || !$prev_record['is_paid']) {
        // Target is Previous Month
        $target_month = $prev_month;
        $target_year = $prev_year;
    } else {
        // Target is Current Month
        $target_month = $current_month;
        $target_year = $current_year;
    }

    // Now Insert or Update the Target Month
    $stmt->execute([$tax_type, $target_month, $target_year]); // Check target existence
    $target_record = $stmt->fetch();

    if ($target_record) {
        $sql = "UPDATE monthly_tax_status SET is_paid = 1, amount_paid = ?, date_paid = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$amount, $target_record['id']]);
    } else {
        $sql = "INSERT INTO monthly_tax_status (tax_type, month, year, amount_paid, date_paid, is_paid) VALUES (?, ?, ?, ?, NOW(), 1)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tax_type, $target_month, $target_year, $amount]);
    }

    echo json_encode(['status' => 'success', 'message' => "Tax ($tax_type) for $target_month/$target_year marked as paid."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>