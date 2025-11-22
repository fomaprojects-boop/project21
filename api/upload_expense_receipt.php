<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

// Only Accountants and Admins can pay/upload receipts
require_role(['Accountant', 'Admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Check for file upload error first
if (empty($_FILES['receipt_file']) || $_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
     echo json_encode(['status' => 'error', 'message' => 'A valid receipt file is required.']);
     exit;
}

if (empty($_POST['expense_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Expense ID is required.']);
    exit;
}

$expenseId = $_POST['expense_id'];
$file = $_FILES['receipt_file'];
$userId = $_SESSION['user_id'];
$paidAt = date('Y-m-d H:i:s');

try {
    $pdo->beginTransaction();

    // Verify expense exists
    $stmt = $pdo->prepare("SELECT status, date FROM direct_expenses WHERE id = ?");
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

    // Allow upload if Approved or already Paid (just updating receipt)
    // User flow is "Pay & Upload", so typically it goes from Approved -> Paid.
    if ($expense['status'] !== 'Approved' && $expense['status'] !== 'Paid') {
        throw new Exception("Expense must be Approved before paying. Current status: " . $expense['status']);
    }

    // Handle File Upload
    $uploadDir = '../uploads/expenses/';
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
             throw new Exception("Failed to create upload directory.");
        }
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array(strtolower($extension), $allowed_exts)) {
         throw new Exception("Invalid file type. Only JPG, PNG, and PDF allowed.");
    }

    $newFilename = 'pay_receipt_' . $expenseId . '_' . time() . '.' . $extension;
    $targetPath = $uploadDir . $newFilename;
    $dbPath = 'uploads/expenses/' . $newFilename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Failed to move uploaded file.");
    }

    // Update Status to Paid and save receipt URL
    // If it was already Paid, this just updates the receipt (and paid_at if we want, but keeping original paid_at might be better?
    // Let's update paid_at to now since this is the confirmation action)
    $updateStmt = $pdo->prepare("UPDATE direct_expenses SET status = 'Paid', paid_at = ?, payment_receipt_url = ? WHERE id = ?");
    $updateStmt->execute([$paidAt, $dbPath, $expenseId]);

    // Log history if it wasn't already paid
    if ($expense['status'] !== 'Paid') {
        $histStmt = $pdo->prepare("INSERT INTO expense_approval_history (expense_id, user_id, status, comment) VALUES (?, ?, 'Paid', 'Payment confirmed and receipt uploaded')");
        $histStmt->execute([$expenseId, $userId]);
    }

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Payment confirmed and receipt uploaded successfully.', 'url' => $dbPath]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
