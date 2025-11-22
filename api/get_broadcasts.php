<?php
    // api/get_broadcasts.php
    session_start();
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    require_once 'db.php';

    try {
        $stmt = $pdo->query("SELECT id, campaign_name, status, scheduled_at FROM broadcasts ORDER BY scheduled_at DESC");
        $broadcasts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($broadcasts);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    ?>