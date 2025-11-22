<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// Only Admins can post to the General Ledger
require_admin();

if (!FEATURE_ENHANCED_EXPENSE_WORKFLOW) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Feature not enabled']);
    exit();
}

if (empty($_POST['source_type']) || empty($_POST['source_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing source_type or source_id']);
    exit();
}

$source_type = $_POST['source_type'];
$source_id = $_POST['source_id'];
$user_id = $_SESSION['user_id'];

// This is a simplified mapping. In a real application, this would be more complex.
$account_mapping = [
    'prepaid_electricity' => ['debit' => '6010', 'credit' => '1010'],
    'internet_bundle' => ['debit' => '6020', 'credit' => '1010'],
    // Add other mappings here
];

try {
    if ($source_type === 'direct_expense') {
        $stmt = $pdo->prepare('SELECT * FROM direct_expenses WHERE id = ?');
        $stmt->execute([$source_id]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$expense) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Expense not found']);
            exit();
        }
        
        $mapping = $account_mapping[$expense['expense_type']];
        if (!$mapping) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No account mapping found for this expense type']);
            exit();
        }

        $debit_account_code = $mapping['debit'];
        $credit_account_code = $mapping['credit'];

        // Get account IDs from codes
        $stmt = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE code IN (?, ?)');
        $stmt->execute([$debit_account_code, $credit_account_code]);
        $accounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $debit_account_id = $accounts[$debit_account_code];
        $credit_account_id = $accounts[$credit_account_code];

        // Create GL Entry
        $description = "Direct expense posting for " . $expense['expense_type'];
        $stmt = $pdo->prepare('INSERT INTO gl_entries (entry_date, description, source_type, source_id, posted_by_user_id, posted_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$expense['date'], $description, $source_type, $source_id, $user_id]);
        $gl_entry_id = $pdo->lastInsertId();

        // Create GL Lines
        $stmt = $pdo->prepare('INSERT INTO gl_lines (gl_entry_id, account_id, debit, credit, narration) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$gl_entry_id, $debit_account_id, $expense['amount'], 0, 'Debit for ' . $expense['expense_type']]);
        $stmt->execute([$gl_entry_id, $credit_account_id, 0, $expense['amount'], 'Credit for ' . $expense['expense_type']]);
        
        echo json_encode(['status' => 'success', 'gl_entry_id' => $gl_entry_id]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unsupported source_type']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>