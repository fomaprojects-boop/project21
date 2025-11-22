<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

// Only Accountants and Admins can mark expenses as paid
require_role(['Accountant', 'Admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (empty($_POST['expense_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Expense ID is required.']);
    exit;
}

$expenseId = $_POST['expense_id'];
$paidAt = date('Y-m-d H:i:s');
$userId = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    // Check current status
    $stmt = $pdo->prepare("SELECT status, type, date FROM direct_expenses WHERE id = ?");
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        throw new Exception("Expense not found.");
    }
    
    // Financial Year Check
    $financial_year_to_check = date('Y', strtotime($expense['date']));
    $stmt_year = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
    $stmt_year->execute([$financial_year_to_check]);
    $year_status = $stmt_year->fetch(PDO::FETCH_ASSOC);

    if ($year_status && $year_status['is_closed']) {
        throw new Exception("Financial year {$financial_year_to_check} is closed.");
    }

    // Allow marking as paid if it's Approved (for requisitions) or Approved for Payment (for claims)
    // We also allow "Approved" generally just to be safe with variations
    if (!in_array($expense['status'], ['Approved', 'Approved for Payment'])) {
         throw new Exception("Expense must be Approved before marking as Paid. Current status: " . $expense['status']);
    }

    // Update status to Paid
    $updateStmt = $pdo->prepare("UPDATE direct_expenses SET status = 'Paid', paid_at = ? WHERE id = ?");
    $updateStmt->execute([$paidAt, $expenseId]);

    // Log history
    $histStmt = $pdo->prepare("INSERT INTO expense_approval_history (expense_id, user_id, status, comment) VALUES (?, ?, 'Paid', 'Marked as Paid')");
    $histStmt->execute([$expenseId, $userId]);

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Expense marked as Paid.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>