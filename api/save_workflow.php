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
$trigger_type = $data['trigger_type'] ?? 'KEYWORD';
$keywords = $data['keywords'] ?? '';
$is_active = isset($data['is_active']) ? (int)$data['is_active'] : 0;
$steps = $data['steps'] ?? []; // New linear steps array

if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'Workflow name cannot be empty.']);
    exit();
}

try {
    $pdo->beginTransaction();

    if ($workflowId) {
        // Update existing workflow meta
        $stmt = $pdo->prepare(
            "UPDATE workflows SET name = ?, trigger_type = ?, keywords = ?, is_active = ? WHERE id = ?"
        );
        $stmt->execute([$name, $trigger_type, $keywords, $is_active, $workflowId]);

        // Remove old steps to replace with new ones (easiest logic for linear list editing)
        $stmtDelete = $pdo->prepare("DELETE FROM workflow_steps WHERE workflow_id = ?");
        $stmtDelete->execute([$workflowId]);

    } else {
        // Insert new workflow
        $stmt = $pdo->prepare(
            "INSERT INTO workflows (name, trigger_type, keywords, is_active) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$name, $trigger_type, $keywords, $is_active]);
        $workflowId = $pdo->lastInsertId();
    }

    // Insert Steps
    if (!empty($steps) && is_array($steps)) {
        $sqlStep = "INSERT INTO workflow_steps (workflow_id, step_order, action_type, content, meta_data) VALUES (?, ?, ?, ?, ?)";
        $stmtStep = $pdo->prepare($sqlStep);

        foreach ($steps as $index => $step) {
            $stepOrder = $index + 1;
            $actionType = $step['action_type'];
            $content = $step['content'] ?? null;
            $metaData = isset($step['meta_data']) ? json_encode($step['meta_data']) : null;

            $stmtStep->execute([$workflowId, $stepOrder, $actionType, $content, $metaData]);
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Workflow saved successfully!', 'id' => $workflowId]);

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Database error in save_workflow.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
