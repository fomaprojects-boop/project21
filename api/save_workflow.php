<?php
// api/save_workflow.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

$workflowId = $data['id'] ?? null;
$name = trim($data['name'] ?? 'Untitled Workflow');
$trigger_type = $data['trigger_type'] ?? 'Unknown';
$workflow_data = json_encode($data['workflow_data'] ?? ['nodes' => []]); // Re-encode to store as JSON string

if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'Workflow name cannot be empty.']);
    exit();
}

try {
    if ($workflowId) {
        // Update existing workflow
        $stmt = $pdo->prepare(
            "UPDATE workflows SET name = ?, trigger_type = ?, workflow_data = ? WHERE id = ?"
        );
        $stmt->execute([$name, $trigger_type, $workflow_data, $workflowId]);
        $message = 'Workflow updated successfully!';
    } else {
        // Insert new workflow
        $stmt = $pdo->prepare(
            "INSERT INTO workflows (name, trigger_type, workflow_data) VALUES (?, ?, ?)"
        );
        $stmt->execute([$name, $trigger_type, $workflow_data]);
        $workflowId = $pdo->lastInsertId();
        $message = 'Workflow created successfully!';
    }

    echo json_encode(['status' => 'success', 'message' => $message, 'id' => $workflowId]);

} catch (PDOException $e) {
    error_log('Database error in save_workflow.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: Could not save workflow.']);
}
?>
