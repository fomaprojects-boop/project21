<?php
// api/get_tax_history.php
require_once 'config.php';
require_once 'db.php';

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$type = $_GET['type'] ?? '';

if (!$type) {
    echo json_encode(['status' => 'error', 'message' => 'Tax type required']);
    exit();
}

try {
    $history = [];

    if ($type === 'Stamp Duty') {
        $stmt = $pdo->prepare("
            SELECT
                submitted_at as date_paid,
                (amount * 0.01) as amount,
                DATE_FORMAT(submitted_at, '%M') as period_month,
                YEAR(submitted_at) as period_year,
                transaction_reference as reference_number,
                payment_receipt_url as receipt_path,
                'Stamp Duty' as tax_type
            FROM payout_requests
            WHERE service_type = 'Rent'
            AND (status = 'Approved' OR status = 'Paid' OR status = 'Processed')
            ORDER BY submitted_at DESC
        ");
        $stmt->execute();
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        $stmt = $pdo->prepare("
            SELECT
                date_paid,
                amount_paid as amount,
                month as period_month_num,
                year as period_year,
                NULL as reference_number,
                NULL as receipt_path
            FROM monthly_tax_status
            WHERE tax_type = ?
            AND is_paid = 1
            ORDER BY date_paid DESC, year DESC, month DESC
        ");
        $stmt->execute([$type]);
        $raw_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($raw_history as $row) {
            $dateObj = DateTime::createFromFormat('!m', $row['period_month_num']);
            $row['period_month'] = $dateObj->format('F');
            $history[] = $row;
        }
    }

    foreach ($history as &$row) {
        $row['formatted_amount'] = number_format((float)$row['amount'], 2);
        $row['formatted_date'] = $row['date_paid'] ? date('d M Y', strtotime($row['date_paid'])) : 'N/A';
    }

    echo json_encode(['status' => 'success', 'history' => $history]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>