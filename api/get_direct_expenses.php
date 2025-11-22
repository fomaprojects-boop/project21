<?php
require_once 'config.php';
require_once 'db.php';
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
    $userRole = $_SESSION['user_role'];

    $whereClause = '';
    $params = [];

    // --- REKEBISHO #1 HAPA ---
    // Tunaongeza ID ya mtumiaji aliyelogin kwenye 'params'
    // ili tuweze kuitumia kwenye SELECT query hapo chini
    $params[':current_user_id'] = $userId;

    if ($userRole === 'Staff') {
        $whereClause = 'WHERE de.user_id = :user_id';
        $params[':user_id'] = $userId; // Hii ni kwa ajili ya WHERE
    } else if ($userRole === 'Admin' || $userRole === 'Accountant') {
        // Admin na Accountant wanaona zote
        $whereClause = ""; 
    }

    // Get total count
    $totalSql = "SELECT COUNT(de.id) FROM direct_expenses de " . $whereClause;
    $totalStmt = $pdo->prepare($totalSql);
    // Tunachuja 'params' ambazo hazipo kwenye totalSql ili kuepuka error
    $totalParams = $params;
    if (isset($totalParams[':current_user_id']) && strpos($totalSql, ':current_user_id') === false) {
        unset($totalParams[':current_user_id']);
    }
    $totalStmt->execute($totalParams);
    $total = $totalStmt->fetchColumn();


    // Get paginated results
    $sql = "SELECT de.*, 
                   u.full_name as user_name, 
                   au.full_name as assigned_user_name,
                   de.status as original_status,
                   
                   -- --- REKEBISHO #2 HAPA ---
                   -- Tumeongeza logic ya 'Forwarded to you'
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
    
    foreach ($params as $key => &$val) {
        // Tunabind 'params' zote (zikiwemo :current_user_id na :user_id kama ipo)
        $stmt->bindParam($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    unset($val);

    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add an 'is_actionable' flag for the frontend and clean up status field
    foreach ($expenses as &$expense) {
        $isActionable = false;
        if ($userRole === 'Admin' || $userRole === 'Accountant') {
            if ($expense['original_status'] === 'Submitted' || $expense['original_status'] === 'Pending Approval' || ($expense['original_status'] === 'Forwarded' && $expense['assigned_to'] == $userId)) {
                $isActionable = true;
            }
        }
        $expense['is_actionable'] = $isActionable;
        $expense['status'] = $expense['status_display']; // Hii sasa itakuwa na "Forwarded to you"
        unset($expense['status_display'], $expense['original_status']);
    }
    unset($expense); // Unset reference

    echo json_encode([
        'status' => 'success',
        'expenses' => $expenses,
        'total' => $total,
        'page' => $page,
        'limit' => $limit
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>