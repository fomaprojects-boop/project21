<?php
// api/get_workflows.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

require_once 'db.php';

try {
    // Kwa sasa, tunasoma workflows zote. Baadaye unaweza kuongeza user_id ili kila mtumiaji aone zake tu.
    $stmt = $pdo->prepare("SELECT id, name, trigger_type, workflow_data, is_active FROM workflows ORDER BY name");
    $stmt->execute();
    $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode the JSON data for each workflow before sending
    foreach ($workflows as &$workflow) {
        $workflow['workflow_data'] = json_decode($workflow['workflow_data'], true);
    }

    echo json_encode($workflows);

} catch (PDOException $e) {
    error_log('Database error in get_workflows.php: ' . $e->getMessage());
    echo json_encode([]);
}
?>