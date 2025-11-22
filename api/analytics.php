<?php
require_once 'db.php';
require_once 'config.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'Staff' && $_SESSION['user_role'] !== 'Accountant')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to view analytics.']);
    exit();
}

try {
    // 1. Total Orders
    $totalOrdersStmt = $pdo->query("SELECT COUNT(*) FROM job_orders");
    $totalOrders = $totalOrdersStmt->fetchColumn();

    // 2. Completed Jobs
    $completedJobsStmt = $pdo->query("SELECT COUNT(*) FROM job_orders WHERE status = 'Completed'");
    $completedJobs = $completedJobsStmt->fetchColumn();

    // 3. Pending Approvals
    $pendingApprovalsStmt = $pdo->query("SELECT COUNT(*) FROM digital_proofs WHERE status = 'Pending Approval'");
    $pendingApprovals = $pendingApprovalsStmt->fetchColumn();

    // 4. Real Profits from Completed Jobs
    $realProfitsStmt = $pdo->query("SELECT SUM(selling_price - cost_price) FROM job_orders WHERE status = 'Completed'");
    $realProfits = $realProfitsStmt->fetchColumn() ?: 0;

    // 5. Data for Charts
    // Top 5 profitable job types (materials)
    $topJobsStmt = $pdo->query(
        "SELECT material, SUM(selling_price - cost_price) as profit 
         FROM job_orders 
         WHERE status = 'Completed' 
         GROUP BY material 
         ORDER BY profit DESC 
         LIMIT 5"
    );
    $topJobs = $topJobsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Top 5 profitable customers
    $topCustomersStmt = $pdo->query(
        "SELECT u.full_name, SUM(jo.selling_price - jo.cost_price) as profit 
         FROM job_orders jo
         JOIN users u ON jo.customer_id = u.id
         WHERE jo.status = 'Completed'
         GROUP BY jo.customer_id
         ORDER BY profit DESC
         LIMIT 5"
    );
    $topCustomers = $topCustomersStmt->fetchAll(PDO::FETCH_ASSOC);


    echo json_encode([
        'status' => 'success',
        'data' => [
            'total_orders' => (int)$totalOrders,
            'completed_jobs' => (int)$completedJobs,
            'pending_approvals' => (int)$pendingApprovals,
            'total_profits' => (float)$realProfits,
            'charts' => [
                'top_jobs' => $topJobs,
                'top_customers' => $topCustomers
            ]
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Analytics data fetch failed: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve analytics data.']);
}
