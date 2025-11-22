<?php
// api/get_activity_log.php

require_once 'config.php';
require_once 'db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit();
}

$user_id = $_SESSION['user_id'];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3; // Default 3 as requested
$offset = ($page - 1) * $limit;

try {
    // We are fetching activity from two sources: Invoices and Expenses.
    // A better approach for a real system is a dedicated 'audit_logs' table.
    // Here we use UNION to combine them.

    // Count total records for pagination
    $count_sql = "SELECT COUNT(*) as total FROM (
        SELECT id FROM invoices WHERE user_id = ?
        UNION ALL
        SELECT id FROM direct_expenses WHERE user_id = ?
    ) as combined_activity";
    
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute([$user_id, $user_id]);
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Fetch Data
    $sql = "SELECT action, details, created_at, type FROM (
                SELECT 'Created Invoice' as action, invoice_number as details, created_at, 'invoice' as type 
                FROM invoices 
                WHERE user_id = ?
                
                UNION ALL
                
                SELECT 'Submitted Expense' as action, expense_type as details, created_at, 'expense' as type 
                FROM direct_expenses 
                WHERE user_id = ?
            ) as combined_activity
            ORDER BY created_at DESC
            LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $user_id]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for display
    $formatted_activities = [];
    foreach ($activities as $act) {
        $formatted_activities[] = [
            'action' => $act['action'],
            'details' => $act['details'],
            'date' => date('M d, Y H:i', strtotime($act['created_at'])),
            'type' => $act['type']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'data' => $formatted_activities,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'limit' => $limit
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
