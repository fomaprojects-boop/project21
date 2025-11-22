<?php
require_once 'config.php';
require_once 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'Admin' && $_SESSION['user_role'] !== 'Accountant')) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$expenseId = $_POST['expense_id'] ?? 0;
$comment = $_POST['comment'] ?? 'Rejected';

if (empty($expenseId)) {
    echo json_encode(['status' => 'error', 'message' => 'Expense ID is required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check current status and get the date for financial year check
    $stmt = $pdo->prepare("SELECT status, assigned_to, date FROM direct_expenses WHERE id = ?");
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Expense not found.']);
        exit;
    }

    // --- Financial Year-End Closing Guardrail ---
    $financial_year_to_check = date('Y', strtotime($expense['date']));
    $stmt_year = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
    $stmt_year->execute([$financial_year_to_check]);
    $year_status = $stmt_year->fetch(PDO::FETCH_ASSOC);

    if ($year_status && $year_status['is_closed']) {
        $pdo->rollBack();
        http_response_code(403); // Forbidden
        echo json_encode(['status' => 'error', 'message' => "Financial year {$financial_year_to_check} is closed. This expense cannot be rejected."]);
        exit();
    }
    // --- End Guardrail ---

    $currentStatus = $expense['status'];
    $assignedTo = $expense['assigned_to'];

    if ($currentStatus === 'Forwarded' && $assignedTo != $userId) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This expense has been forwarded to another user.']);
        exit;
    }

    if (!in_array($currentStatus, ['Submitted', 'Forwarded', 'Pending Approval'])) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'This expense cannot be rejected from its current state.']);
        exit;
    }

    // Update expense status
    $stmt = $pdo->prepare("UPDATE direct_expenses SET status = 'Rejected' WHERE id = ?");
    $stmt->execute([$expenseId]);

    // Log history
    $stmt = $pdo->prepare("INSERT INTO expense_approval_history (expense_id, user_id, status, comment) VALUES (?, ?, 'Rejected', ?)");
    $stmt->execute([$expenseId, $userId, $comment]);

    $pdo->commit();
    echo json_encode([
        'status' => 'success',
        'message' => 'Expense rejected successfully.'
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>