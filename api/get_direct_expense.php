<?php
require_once 'db.php';
require_once 'config.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

if (!FEATURE_ENHANCED_EXPENSE_WORKFLOW) {
    echo json_encode([]);
    echo json_encode(['status' => 'error', 'message' => 'Feature disabled.']);
    exit();
}

try {
    $stmt = $pdo->prepare('SELECT * FROM direct_expenses ORDER BY date DESC');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Get total count
    $total_stmt = $pdo->prepare('SELECT COUNT(*) FROM direct_expenses');
    $total_stmt->execute();
    $total_expenses = $total_stmt->fetchColumn();

    // Get paginated results
    $stmt = $pdo->prepare('SELECT * FROM direct_expenses ORDER BY date DESC LIMIT :limit OFFSET :offset');
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($expenses);
    echo json_encode([
        'status' => 'success',
        'expenses' => $expenses,
        'total' => $total_expenses,
        'page' => $page,
        'limit' => $limit
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([]);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>