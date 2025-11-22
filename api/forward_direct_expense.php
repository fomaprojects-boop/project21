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
$forwardUserId = $_POST['forward_user_id'] ?? 0;
$comment = $_POST['comment'] ?? 'Forwarded';

if (empty($expenseId) || empty($forwardUserId)) {
    echo json_encode(['status' => 'error', 'message' => 'Expense ID and Forward User ID are required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check current status
    $stmt = $pdo->prepare("SELECT status, assigned_to FROM direct_expenses WHERE id = ?");
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Expense not found.']);
        exit;
    }

    $currentStatus = $expense['status'];
    $assignedTo = $expense['assigned_to'];

    if ($currentStatus === 'Forwarded' && $assignedTo != $userId) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'This expense has been forwarded to another user.']);
        exit;
    }

    // Update expense status
    $stmt = $pdo->prepare("UPDATE direct_expenses SET status = 'Forwarded', assigned_to = ? WHERE id = ?");
    $stmt->execute([$forwardUserId, $expenseId]);

    // Log history
    $stmt = $pdo->prepare("INSERT INTO expense_approval_history (expense_id, user_id, status, comment) VALUES (?, ?, 'Forwarded', ?)");
    $stmt->execute([$expenseId, $userId, $comment]);

    $pdo->commit();
    echo json_encode([
        'status' => 'success',
        'message' => 'Expense forwarded successfully.'
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}