<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

// Ideally, only Accountants/Admins or maybe the user who requested (if they are providing proof) can upload.
// For now, we'll restrict to Accountant/Admin as per the "muhasibu" requirement.
require_role(['Accountant', 'Admin']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if (empty($_POST['expense_id']) || empty($_FILES['payment_receipt'])) {
    echo json_encode(['status' => 'error', 'message' => 'Expense ID and receipt file are required.']);
    exit;
}

$expenseId = $_POST['expense_id'];
$file = $_FILES['payment_receipt'];

try {
    // Verify expense exists and is Paid
    $stmt = $pdo->prepare("SELECT status FROM direct_expenses WHERE id = ?");
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        throw new Exception("Expense not found.");
    }
    
    // Optional: Ensure it is 'Paid' before uploading payment receipt? 
    // The user said "akishaset paid pawe na option ya pili". So yes.
    if ($expense['status'] !== 'Paid') {
        throw new Exception("Expense must be marked as Paid before uploading a payment receipt.");
    }

    // Handle File Upload
    $uploadDir = '../uploads/expenses/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFilename = 'pay_receipt_' . $expenseId . '_' . time() . '.' . $extension;
    $targetPath = $uploadDir . $newFilename;
    
    // URL path for database (relative to base URL, usually 'uploads/...')
    $dbPath = 'uploads/expenses/' . $newFilename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Failed to move uploaded file.");
    }

    // Update Database
    $updateStmt = $pdo->prepare("UPDATE direct_expenses SET payment_receipt_url = ? WHERE id = ?");
    $updateStmt->execute([$dbPath, $expenseId]);

    echo json_encode(['status' => 'success', 'message' => 'Payment receipt uploaded successfully.', 'url' => $dbPath]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>