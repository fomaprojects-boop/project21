<?php
// api/approve_payroll_batch.php
require_once 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'] ?? 'Staff';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$batch_id = $data['batch_id'] ?? null;

if (empty($batch_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Batch ID is required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check if the batch exists, is pending, and if the user is authorized
    $stmt_check = $pdo->prepare("SELECT status, approver_id FROM payroll_batches WHERE id = ?");
    $stmt_check->execute([$batch_id]);
    $batch = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        http_response_code(404);
        throw new Exception("Payroll batch not found.");
    }

    if ($batch['status'] !== 'pending_approval') {
        http_response_code(409); // Conflict
        throw new Exception("This payroll batch has already been actioned.");
    }

    // Authorization check: User must be the designated approver or an Admin
    if ($batch['approver_id'] != $current_user_id && $current_user_role !== 'Admin') {
        http_response_code(403); // Forbidden
        throw new Exception("You are not authorized to approve this payroll batch.");
    }

    // Update the batch status
    $stmt = $pdo->prepare(
        "UPDATE payroll_batches 
         SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP 
         WHERE id = ?"
    );
    $stmt->execute([$current_user_id, $batch_id]);

    $pdo->commit();

    echo json_encode(['status' => 'success', 'message' => 'Payroll batch approved successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // If we didn't set a specific HTTP code, use 500
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
