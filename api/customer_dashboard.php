<?php
require_once 'db.php';
require_once 'config.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'Client';

if ($method === 'GET') {
    $customerId = $userId;
    if (($userRole === 'Admin' || $userRole === 'Staff' || $userRole === 'Accountant') && isset($_GET['customer_id'])) {
        // Allow privileged roles to view a specific customer's dashboard
        $customerId = intval($_GET['customer_id']);
    }

    try {
        // 1. Fetch Job Orders for the customer
        $ordersStmt = $pdo->prepare("SELECT id, tracking_number, status, created_at FROM job_orders WHERE customer_id = :customer_id ORDER BY created_at DESC");
        $ordersStmt->bindParam(':customer_id', $customerId);
        $ordersStmt->execute();
        $jobOrders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Fetch Proofs Awaiting Approval for the customer
        $proofsStmt = $pdo->prepare(
            "SELECT dp.id, dp.file_path, jo.tracking_number
             FROM digital_proofs dp
             JOIN job_orders jo ON dp.job_order_id = jo.id
             WHERE jo.customer_id = :customer_id AND dp.status = 'Pending Approval'
             ORDER BY dp.created_at DESC"
        );
        $proofsStmt->bindParam(':customer_id', $customerId);
        $proofsStmt->execute();
        $proofsForApproval = $proofsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success', 
            'job_orders' => $jobOrders,
            'proofs_for_approval' => $proofsForApproval
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Customer dashboard fetch failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve dashboard data.']);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Only GET is supported.']);
}
