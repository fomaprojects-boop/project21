<?php
// api/delete_workflow.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);
$workflowId = $data['id'] ?? null;

if (empty($workflowId)) {
    echo json_encode(['status' => 'error', 'message' => 'Workflow ID is required.']);
    exit();
}

try {
    // Kwa usalama zaidi, unaweza kuongeza hapa uthibitisho wa kuhakikisha
    // mtumiaji anayefuta ndiye mmiliki wa workflow
    
    $stmt = $pdo->prepare("DELETE FROM workflows WHERE id = ?");
    $stmt->execute([$workflowId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Workflow deleted successfully!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Workflow not found or could not be deleted.']);
    }

} catch (PDOException $e) {
    error_log('Database error in delete_workflow.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: Could not delete workflow.']);
}
?>
