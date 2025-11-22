<?php
// api/get_reports_data.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get filter parameters from the request
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;
$status = $_GET['status'] ?? 'All';
$document_type = $_GET['document_type'] ?? 'All';

try {
    // Base query for transactions
    $sql = "SELECT c.name as customer_name, i.invoice_number, i.document_type, i.issue_date, i.due_date, i.total_amount, i.amount_paid, i.balance_due, i.status
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.user_id = :user_id";

    $params = ['user_id' => $user_id];

    if ($start_date) {
        $sql .= " AND i.issue_date >= :start_date";
        $params['start_date'] = $start_date;
    }
    if ($end_date) {
        $sql .= " AND i.issue_date <= :end_date";
        $params['end_date'] = $end_date;
    }
    if ($status !== 'All') {
        $sql .= " AND i.status = :status";
        $params['status'] = $status;
    }
    if ($document_type !== 'All') {
        $sql .= " AND i.document_type = :document_type";
        $params['document_type'] = $document_type;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Overdue customers query
    $overdue_sql = "SELECT c.name as customer_name, i.invoice_number, i.due_date, i.balance_due
                    FROM invoices i
                    JOIN customers c ON i.customer_id = c.id
                    WHERE i.user_id = :user_id AND i.status = 'Unpaid' AND i.due_date < CURDATE()
                    ORDER BY i.due_date ASC";
    
    $overdue_stmt = $pdo->prepare($overdue_sql);
    $overdue_stmt->execute(['user_id' => $user_id]);
    $overdue_customers = $overdue_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare summary
    $summary = [
        'paid' => ['total' => 0, 'count' => 0],
        'partially_paid' => ['total' => 0, 'count' => 0],
        'unpaid' => ['total' => 0, 'count' => 0],
    ];

    foreach($transactions as $transaction) {
        if($transaction['status'] == 'Paid') {
            $summary['paid']['total'] += $transaction['total_amount'];
            $summary['paid']['count']++;
        } elseif($transaction['status'] == 'Partially Paid') {
            $summary['partially_paid']['total'] += $transaction['balance_due'];
            $summary['partially_paid']['count']++;
        } elseif($transaction['status'] == 'Unpaid') {
            $summary['unpaid']['total'] += $transaction['balance_due'];
            $summary['unpaid']['count']++;
        }
    }

    echo json_encode([
        'status' => 'success',
        'transactions' => $transactions,
        'summary' => $summary,
        'overdue_customers' => $overdue_customers
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
