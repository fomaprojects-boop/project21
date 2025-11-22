<?php
// api/get_payroll_batches.php
require_once 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'] ?? 'Staff';

$status_filter = $_GET['status'] ?? null;

try {
    $sql = "SELECT 
                pb.id, 
                pb.month, 
                pb.year, 
                pb.status, 
                pb.total_amount, 
                pb.original_filename,
                u_uploader.full_name as uploaded_by_name,
                u_approver.full_name as approver_name,
                pb.uploaded_at,
                pb.approver_id
            FROM 
                payroll_batches pb
            JOIN 
                users u_uploader ON pb.uploaded_by = u_uploader.id
            LEFT JOIN
                users u_approver ON pb.approver_id = u_approver.id";

    $params = [];
    $where_clauses = [];

    // Role-based filtering
    if ($current_user_role !== 'Admin') {
        $where_clauses[] = "(pb.uploaded_by = ? OR pb.approver_id = ?)";
        $params[] = $current_user_id;
        $params[] = $current_user_id;
    }

    if ($status_filter && $status_filter !== 'All') {
        $where_clauses[] = "pb.status = ?";
        $params[] = $status_filter;
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(' AND ', $where_clauses);
    }
    
    $sql .= " ORDER BY pb.uploaded_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($batches as &$batch) {
        $batch['is_actionable'] = ($batch['status'] == 'pending_approval' && $batch['approver_id'] == $current_user_id);
    }

    echo json_encode(['status' => 'success', 'data' => $batches]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
