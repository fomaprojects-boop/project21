<?php
// Anza na hizi 'require'
require_once 'db.php';
require_once 'config.php';

session_start();

// Weka header ya JSON mapema
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    // Rudisha error yenye maelezo
    echo json_encode(['status' => 'error', 'message' => 'User not logged in.']);
    exit();
}

if (!FEATURE_ENHANCED_EXPENSE_WORKFLOW) {
    // Rudisha error yenye maelezo
    echo json_encode(['status' => 'error', 'message' => 'Feature not enabled.']);
    exit();
}

if (!isset($_GET['expense_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameter: expense_id']);
    exit();
}

$expense_id = $_GET['expense_id'];

try {
    // Get expense details - HII ILIKUWA SAHIHI
    $stmt_expense = $pdo->prepare('SELECT id, status, reference, tracking_number, attachment_url FROM direct_expenses WHERE id = ?');
    $stmt_expense->execute([$expense_id]);
    $expense_details = $stmt_expense->fetch(PDO::FETCH_ASSOC);

    // Get history
    $stmt_history = $pdo->prepare('
        SELECT 
            h.status,
            h.comment, 
            h.created_at,
            u.full_name AS user_name,
            u.role 
        FROM expense_approval_history h
        LEFT JOIN users u ON h.user_id = u.id
        WHERE h.expense_id = ?
        ORDER BY h.created_at ASC
    ');
    
    $stmt_history->execute([$expense_id]);
    $history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);

    // Combine and send data
    echo json_encode([
        'status' => 'success',
        'expense' => $expense_details,
        'history' => $history
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    // Tuma error katika muundo sahihi
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>