<?php
// api/get_workflow_details.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

require_once 'db.php';

$workflowId = $_GET['id'] ?? null;

if (!$workflowId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing Workflow ID']);
    exit();
}

try {
    // 1. Fetch Workflow Info
    $stmt = $pdo->prepare("SELECT id, name, trigger_type, keywords, is_active FROM workflows WHERE id = ?");
    $stmt->execute([$workflowId]);
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$workflow) {
        echo json_encode(['status' => 'error', 'message' => 'Workflow not found']);
        exit();
    }

    // 2. Fetch Steps
    $stmtSteps = $pdo->prepare("SELECT id, step_order, action_type, content, meta_data FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC");
    $stmtSteps->execute([$workflowId]);
    $steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

    // Decode meta_data
    foreach ($steps as &$step) {
        if ($step['meta_data']) {
            $step['meta_data'] = json_decode($step['meta_data'], true);
        }
    }

    $workflow['steps'] = $steps;

    echo json_encode(['status' => 'success', 'data' => $workflow]);

} catch (PDOException $e) {
    error_log('Database error in get_workflow_details.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
?>
