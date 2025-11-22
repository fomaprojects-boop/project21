<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Only Admins and Accountants can approve expenses
require_role(['Admin', 'Accountant']);

if (!FEATURE_ENHANCED_EXPENSE_WORKFLOW) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Feature not enabled']);
    exit();
}

if (empty($_POST['expense_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameter: expense_id']);
    exit();
}

$expense_id = $_POST['expense_id'];
$comment = $_POST['comment'] ?? 'Approved';
$approver_id = $_SESSION['user_id'];
$approved_at = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    // Get more details about the expense, including its date for the financial year check
    $stmt = $pdo->prepare("SELECT type, status, assigned_to, date FROM direct_expenses WHERE id = ?");
    $stmt->execute([$expense_id]);
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
        echo json_encode(['status' => 'error', 'message' => "Financial year {$financial_year_to_check} is closed. This expense cannot be approved."]);
        exit();
    }
    // --- End Guardrail ---

    $expenseType = $expense['type'];
    $currentStatus = $expense['status'];
    $assignedTo = $expense['assigned_to'];

    // Security and state check
    if ($currentStatus === 'Forwarded' && $assignedTo != $approver_id) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'This expense is assigned to another user.']);
        exit;
    }

    $allowed_statuses_for_approval = ['Submitted', 'Forwarded', 'Pending'];
    // Check status case-insensitively
    $currentStatusNormalized = ucfirst(strtolower($currentStatus));

    // Also allow 'Forwarded' if it starts with 'Forwarded' (e.g., 'Forwarded to John')
    $isForwarded = strpos($currentStatusNormalized, 'Forwarded') === 0;

    if (!in_array($currentStatusNormalized, $allowed_statuses_for_approval) && !$isForwarded) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'This expense cannot be approved from its current state. Current status: ' . $currentStatus]);
        exit;
    }

    // Determine the new status
    // A claim for reimbursement is approved, it is now ready to be paid.
    // A requisition is approved, it means the funds are authorized for spending.
    // User requested status to be just 'Approved' for brevity.
    $newStatus = 'Approved';

    $stmt = $pdo->prepare('UPDATE direct_expenses SET status = ?, approver_id = ?, approved_at = ? WHERE id = ?');
    $stmt->execute([$newStatus, $approver_id, $approved_at, $expense_id]);

    $stmt = $pdo->prepare('INSERT INTO expense_approval_history (expense_id, user_id, status, comment) VALUES (?, ?, ?, ?)');
    $stmt->execute([$expense_id, $approver_id, $newStatus, $comment]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'id' => $expense_id,
        'new_status' => $newStatus,
        'message' => 'Expense approved successfully.'
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>