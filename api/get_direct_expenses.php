<?php
require_once 'config.php';
require_once 'db.php';
require_once 'helpers/PermissionHelper.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
$offset = ($page - 1) * $limit;

try {
    $userId = $_SESSION['user_id'];
    $tenantId = getCurrentTenantId();

    $whereClause = 'WHERE de.tenant_id = :tenant_id';
    $params = [':tenant_id' => $tenantId];

    // RBAC Logic
    if (hasPermission($userId, 'view_all_expenses')) {
        // Can view all expenses for the tenant
        // No additional filter needed
    } elseif (hasPermission($userId, 'view_own_expenses')) {
        // Can only view their own
        $whereClause .= ' AND de.user_id = :user_id';
        $params[':user_id'] = $userId;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Access Denied: view_expenses permission required.']);
        exit;
    }

    // Get total count
    $totalSql = "SELECT COUNT(de.id) FROM direct_expenses de " . $whereClause;
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($params);
    $total = $totalStmt->fetchColumn();


    // Get paginated results
    // We pass :current_user_id for the status_display logic in subquery if needed,
    // or we handle it in PHP. Let's do it in SQL as before.

    // Note: We need to bind :limit and :offset specially

    $sql = "SELECT de.*,
                   u.full_name as user_name,
                   au.full_name as assigned_user_name,
                   de.status as original_status,

                   CASE
                       WHEN de.status = 'Forwarded' AND de.assigned_to = :current_user_id
                       THEN 'Forwarded to you'

                       WHEN de.status = 'Forwarded' AND au.full_name IS NOT NULL
                       THEN CONCAT('Forwarded to ', au.full_name)

                       ELSE de.status
                   END as status_display

            FROM direct_expenses de
            JOIN users u ON de.user_id = u.id
            LEFT JOIN users au ON de.assigned_to = au.id
            " . $whereClause . "
            ORDER BY de.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    // Bind current_user_id for the CASE statement
    $stmt->bindValue(':current_user_id', $userId);

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Permission check for Actions
    $canApprove = hasPermission($userId, 'approve_expenses');

    foreach ($expenses as &$expense) {
        $isActionable = false;

        // Logic for Actionable:
        // 1. User has permission to approve
        // 2. Status is Submitted/Pending Approval OR Forwarded to this user
        if ($canApprove) {
            if ($expense['original_status'] === 'Submitted' ||
                $expense['original_status'] === 'Pending Approval' ||
                ($expense['original_status'] === 'Forwarded' && $expense['assigned_to'] == $userId)) {
                $isActionable = true;
            }
        }

        $expense['is_actionable'] = $isActionable;
        $expense['status'] = $expense['status_display'];
        unset($expense['status_display'], $expense['original_status']);
    }
    unset($expense);

    echo json_encode([
        'status' => 'success',
        'expenses' => $expenses,
        'total' => $total,
        'page' => $page,
        'limit' => $limit
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
