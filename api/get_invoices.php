<?php
// api/get_invoices.php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');

// Get filter parameters
$status_filter = $_GET['status'] ?? 'All';
$period_filter = $_GET['period'] ?? 'all';
$doc_types_filter = $_GET['doc_types'] ?? [];

// Pagination parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

try {
    // --- Build Base Query and Params ---
    $base_where_sql = " FROM invoices i WHERE i.user_id = ? AND i.status <> 'Converted'";
    $base_params = [$user_id];

    switch ($period_filter) {
        case 'day': $base_where_sql .= " AND i.issue_date = CURDATE()"; break;
        case 'week': $base_where_sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)"; break;
        case 'month': $base_where_sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"; break;
        case 'year': $base_where_sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)"; break;
    }

    // --- Accurate Status Counts ---
    $status_counts_sql = "SELECT status, COUNT(id) as count " . $base_where_sql . " GROUP BY status";
    $stmt_status_counts = $pdo->prepare($status_counts_sql);
    $stmt_status_counts->execute($base_params);
    $status_counts_raw = $stmt_status_counts->fetchAll(PDO::FETCH_KEY_PAIR);

    $overdue_sql = "SELECT COUNT(id) " . $base_where_sql . " AND status <> 'Paid' AND due_date < CURDATE()";
    $stmt_overdue = $pdo->prepare($overdue_sql);
    $stmt_overdue->execute($base_params);
    $overdue_count = $stmt_overdue->fetchColumn();

    $status_counts = [
        'All' => array_sum($status_counts_raw),
        'Paid' => $status_counts_raw['Paid'] ?? 0,
        'Unpaid' => $status_counts_raw['Unpaid'] ?? 0,
        'Partially Paid' => $status_counts_raw['Partially Paid'] ?? 0,
        'Overdue' => $overdue_count
    ];

    // --- Build Main Query and Summary Query ---
    $main_where_sql = " FROM invoices i LEFT JOIN customers cust ON i.customer_id = cust.id WHERE i.user_id = ? AND i.status <> 'Converted'";
    $main_params = [$user_id];

    // Add time period filter
    switch ($period_filter) {
        case 'day': $main_where_sql .= " AND i.issue_date = CURDATE()"; break;
        case 'week': $main_where_sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)"; break;
        case 'month': $main_where_sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"; break;
        case 'year': $main_where_sql .= " AND i.issue_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)"; break;
    }

    // Add status filter
    if ($status_filter !== 'All') {
        if ($status_filter === 'Overdue') {
            $main_where_sql .= " AND i.status <> 'Paid' AND i.due_date < CURDATE()";
        } else {
            $main_where_sql .= " AND i.status = ?";
            $main_params[] = $status_filter;
        }
    }

    // Add document type filter
    if (!empty($doc_types_filter) && is_array($doc_types_filter)) {
        $placeholders = implode(',', array_fill(0, count($doc_types_filter), '?'));
        $main_where_sql .= " AND i.document_type IN ($placeholders)";
        $main_params = array_merge($main_params, $doc_types_filter);
    }

    // --- Execute Queries ---

    // Get total count for pagination
    $count_sql = "SELECT COUNT(i.id)" . $main_where_sql;
    $stmt_count = $pdo->prepare($count_sql);
    $stmt_count->execute($main_params);
    $total_records = $stmt_count->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Main query for fetching paginated data
    $sql = "SELECT i.id, i.invoice_number, i.document_type, cust.name AS customer_name, i.issue_date, i.due_date, i.total_amount, i.amount_paid, i.balance_due, i.status, i.pdf_url, DATEDIFF(CURDATE(), i.due_date) AS overdue_days" . $main_where_sql;
    $sql .= " ORDER BY i.id DESC LIMIT ? OFFSET ?";

    $stmt_invoices = $pdo->prepare($sql);

    // Manually bind parameters to ensure correct types for LIMIT and OFFSET
    $param_index = 1;
    foreach ($main_params as $param) {
        $stmt_invoices->bindValue($param_index++, $param);
    }
    $stmt_invoices->bindValue($param_index++, $limit, PDO::PARAM_INT);
    $stmt_invoices->bindValue($param_index++, $offset, PDO::PARAM_INT);

    $stmt_invoices->execute();
    $invoices = $stmt_invoices->fetchAll(PDO::FETCH_ASSOC);

    // Summary data - now respects all filters
    $summary_sql = "SELECT SUM(total_amount) AS total_billed, SUM(amount_paid) AS total_paid, SUM(balance_due) AS total_due" . $main_where_sql;
    $stmt_summary = $pdo->prepare($summary_sql);
    $stmt_summary->execute($main_params);
    $summary = $stmt_summary->fetch(PDO::FETCH_ASSOC);

    // --- Format Response ---
    $response = [
        'invoices' => $invoices,
        'pagination' => [
            'currentPage' => $page,
            'totalPages' => $total_pages,
            'totalRecords' => $total_records
        ],
        'summary' => [
            'total_billed' => $summary['total_billed'] ?? 0,
            'total_paid'   => $summary['total_paid'] ?? 0,
            'total_due'    => $summary['total_due'] ?? 0,
        ],
        'status_counts' => $status_counts
    ];

    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
