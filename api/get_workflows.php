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
    // 1. Fetch main workflows
    $stmt = $pdo->prepare("SELECT id, name, trigger_type, keywords, is_active FROM workflows ORDER BY name");
    $stmt->execute();
    $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch steps for each workflow (or can be done via separate endpoint for details, but eager load is okay for list size)
    // Actually, user wants "Builder" which implies editing one. List view just needs summary.
    // Let's modify to check if specific ID is requested? Or client calls a new endpoint.
    // The previous implementation decoded workflow_data.

    // For now, return basic list. The details (steps) will be fetched by get_workflow_details.php

    echo json_encode($workflows);

} catch (PDOException $e) {
    error_log('Database error in get_workflows.php: ' . $e->getMessage());
    echo json_encode([]);
}
?>
