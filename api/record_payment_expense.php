<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Only Admins and Accountants can record payments
require_role(['Admin', 'Accountant']);

if (!FEATURE_ENHANCED_EXPENSE_WORKFLOW) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Feature not enabled']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['expense_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameter: expense_id']);
    exit();
}

$expense_id = $data['expense_id'];
$payment_date = $data['payment_date'] ?? date('Y-m-d');
$transaction_ref = $data['transaction_ref'] ?? null;
$payer_id = $_SESSION['user_id'];
$paid_at = date('Y-m-d H:i:s');
$newStatus = 'Paid';

try {
    $pdo->beginTransaction();

    // --- Financial Year-End Closing Guardrail ---
    $financial_year_to_check = date('Y', strtotime($payment_date));
    $stmt_year = $pdo->prepare("SELECT is_closed FROM financial_years WHERE year = ?");
    $stmt_year->execute([$financial_year_to_check]);
    $year_status = $stmt_year->fetch(PDO::FETCH_ASSOC);

    if ($year_status && $year_status['is_closed']) {
        $pdo->rollBack();
        http_response_code(403); // Forbidden
        echo json_encode(['status' => 'error', 'message' => "Financial year {$financial_year_to_check} is closed. Expense payments cannot be recorded for this period."]);
        exit();
    }
    // --- End Guardrail ---

    // Check current status of the expense
    $stmt = $pdo->prepare("SELECT status FROM direct_expenses WHERE id = ?");
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Expense not found.']);
        exit;
    }

    $currentStatus = $expense['status'];
    $allowed_statuses_for_payment = ['Approved', 'Approved for Payment'];

    if (!in_array($currentStatus, $allowed_statuses_for_payment)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'This expense is not in a state that can be marked as paid.']);
        exit;
    }

    // Update the expense status to 'Paid'
    $stmt = $pdo->prepare('UPDATE direct_expenses SET status = ?, paid_at = ?, transaction_reference = ? WHERE id = ?');
    $stmt->execute([$newStatus, $paid_at, $transaction_ref, $expense_id]);

    // Add a record to the approval history
    $comment = "Payment recorded by user.";
    if ($transaction_ref) {
        $comment .= " Ref: " . $transaction_ref;
    }
    $stmt = $pdo->prepare('INSERT INTO expense_approval_history (expense_id, user_id, status, comment) VALUES (?, ?, ?, ?)');
    $stmt->execute([$expense_id, $payer_id, $newStatus, $comment]);

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Expense successfully marked as paid.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
