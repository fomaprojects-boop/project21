<?php
// api/get_customer_statement.php

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// --- Pagination and Filtering Parameters ---
$customer_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
$period = $_GET['period'] ?? 'all';
$invoices_page = filter_input(INPUT_GET, 'invoices_page', FILTER_VALIDATE_INT) ?: 1;
$payments_page = filter_input(INPUT_GET, 'payments_page', FILTER_VALIDATE_INT) ?: 1;
$limit = 4; // Records per page

if (!$customer_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Valid Customer ID is required.']);
    exit();
}

try {
    // Get Customer Name
    $stmt_customer_name = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
    $stmt_customer_name->execute([$customer_id]);
    $display_name = $stmt_customer_name->fetchColumn();

    if (!$display_name) {
        throw new Exception("Customer not found.");
    }

    // --- Date Range Logic ---
    $date_condition_invoices = "";
    $date_condition_payments1 = "";
    $date_condition_payments2 = "";
    $date_range_display = "All Time";
    $params = [':customer_id' => $customer_id];
    $start_date_obj = null;
    $end_date_obj = new DateTime(); // Today

    switch ($period) {
        case 'day':
            $start_date_obj = new DateTime();
            break;
        case 'week':
            $start_date_obj = new DateTime();
            $start_date_obj->modify('this week');
            break;
        case 'month':
            $start_date_obj = new DateTime('first day of this month');
            break;
        case 'year':
            $start_date_obj = new DateTime('first day of January this year');
            break;
    }

    if ($start_date_obj) {
        $start_date_str = $start_date_obj->format('Y-m-d 00:00:00');
        $end_date_str = $end_date_obj->format('Y-m-d 23:59:59');

        $date_condition_invoices = "AND i.issue_date BETWEEN :start_date AND :end_date";
        $date_condition_payments1 = "AND p.payment_date BETWEEN :start_date AND :end_date";
        $date_condition_payments2 = "AND i.issue_date BETWEEN :start_date AND :end_date";

        $params[':start_date'] = $start_date_str;
        $params[':end_date'] = $end_date_str;
        $date_range_display = $start_date_obj->format('M d, Y') . ' - ' . $end_date_obj->format('M d, Y');
    }

    // --- Invoices Pagination & Data ---
    $invoices_offset = ($invoices_page - 1) * $limit;

    // Count total invoices
    $sql_invoices_count = "SELECT COUNT(i.id) FROM invoices i WHERE i.customer_id = :customer_id $date_condition_invoices";
    $stmt_invoices_count = $pdo->prepare($sql_invoices_count);
    $stmt_invoices_count->execute($params);
    $total_invoices = $stmt_invoices_count->fetchColumn();

    // Fetch paginated invoices
    $sql_invoices = "SELECT i.id, i.invoice_number, i.document_type, i.issue_date, i.due_date, i.total_amount, i.amount_paid, i.balance_due, i.status, i.pdf_url, CASE WHEN i.status IN ('Unpaid', 'Partially Paid', 'Overdue') AND i.due_date < CURDATE() THEN DATEDIFF(CURDATE(), i.due_date) ELSE 0 END AS overdue_days FROM invoices i WHERE i.customer_id = :customer_id $date_condition_invoices ORDER BY i.issue_date DESC, i.id DESC LIMIT :limit OFFSET :offset";
    $stmt_invoices = $pdo->prepare($sql_invoices);
    $stmt_invoices->bindValue(':customer_id', $customer_id, PDO::PARAM_INT);
    if ($start_date_obj) {
        $stmt_invoices->bindValue(':start_date', $params[':start_date']);
        $stmt_invoices->bindValue(':end_date', $params[':end_date']);
    }
    $stmt_invoices->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt_invoices->bindValue(':offset', $invoices_offset, PDO::PARAM_INT);
    $stmt_invoices->execute();
    $invoices = $stmt_invoices->fetchAll(PDO::FETCH_ASSOC);

    // --- Payments Pagination & Data ---
    $payments_offset = ($payments_page - 1) * $limit;

    // Count total payments
    $sql_payments_count = "
        SELECT SUM(c) FROM (
            SELECT COUNT(p.id) as c FROM invoice_payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.customer_id = :customer_id $date_condition_payments1
            UNION ALL
            SELECT COUNT(i.id) as c FROM invoices i WHERE i.customer_id = :customer_id AND i.document_type = 'Receipt' $date_condition_payments2
        ) AS total_counts";
    $stmt_payments_count = $pdo->prepare($sql_payments_count);
    $stmt_payments_count->execute($params);
    $total_payments = $stmt_payments_count->fetchColumn();

    // Fetch paginated payments (Receipts and regular payments)
    $sql_payments = "
        (SELECT p.payment_date as date, p.amount, p.notes, i.invoice_number as document_number, i.pdf_url
         FROM invoice_payments p
         JOIN invoices i ON p.invoice_id = i.id
         WHERE i.customer_id = :customer_id $date_condition_payments1)
        UNION ALL
        (SELECT i.issue_date as date, i.total_amount as amount, 'Payment via Receipt' as notes, i.invoice_number as document_number, i.pdf_url
         FROM invoices i
         WHERE i.customer_id = :customer_id AND i.document_type = 'Receipt' $date_condition_payments2)
        ORDER BY date DESC
        LIMIT :limit OFFSET :offset";
    $stmt_payments = $pdo->prepare($sql_payments);
    $stmt_payments->bindValue(':customer_id', $customer_id, PDO::PARAM_INT);
    if ($start_date_obj) {
        $stmt_payments->bindValue(':start_date', $params[':start_date']);
        $stmt_payments->bindValue(':end_date', $params[':end_date']);
    }
    $stmt_payments->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt_payments->bindValue(':offset', $payments_offset, PDO::PARAM_INT);
    $stmt_payments->execute();
    $payments = $stmt_payments->fetchAll(PDO::FETCH_ASSOC);


    // --- Summary Calculation (Unaffected by pagination) ---
    $summary_date_condition = str_replace('i.issue_date', 'issue_date', $date_condition_invoices);
    $sql_summary = "SELECT SUM(total_amount) as total_billed, SUM(amount_paid) as total_paid FROM invoices WHERE customer_id = :customer_id $summary_date_condition";
    $stmt_summary = $pdo->prepare($sql_summary);
    $stmt_summary->execute($params);
    $summary_data = $stmt_summary->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'customer_name' => $display_name,
        'date_range' => $date_range_display,
        'summary' => [
            'total_billed' => (float)($summary_data['total_billed'] ?? 0),
            'total_paid' => (float)($summary_data['total_paid'] ?? 0),
            'total_due' => (float)($summary_data['total_billed'] ?? 0) - (float)($summary_data['total_paid'] ?? 0)
        ],
        'invoices' => $invoices,
        'payments' => $payments,
        'pagination' => [
            'invoices' => [
                'currentPage' => (int)$invoices_page,
                'totalPages' => ceil($total_invoices / $limit),
                'totalRecords' => (int)$total_invoices
            ],
            'payments' => [
                'currentPage' => (int)$payments_page,
                'totalPages' => ceil($total_payments / $limit),
                'totalRecords' => (int)$total_payments
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("Statement Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error generating statement: ' . $e->getMessage()]);
}
?>
