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

    if ($userRole === 'Staff') {
        $whereClause = 'WHERE de.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    // Get total count
    $totalSql = "SELECT COUNT(*) FROM direct_expenses de " . $whereClause;
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($params);
    $total = $totalStmt->fetchColumn();

    // Get paginated results
    $sql = "SELECT de.*, u.full_name as user_name, a.full_name as assigned_to_name
            FROM direct_expenses de
            JOIN users u ON de.user_id = u.id
            LEFT JOIN users a ON de.assigned_to = a.id
            " . $whereClause . "
            ORDER BY de.created_at DESC
            LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => &$val) {
        $stmt->bindParam($key, $val);
    }
    unset($val);

    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

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