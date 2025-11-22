<?php
require_once 'db.php';
require_once 'config.php';

session_start();
header('Content-Type: application/json');

// Only Admins can manage costs
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to manage costs.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->query("SELECT * FROM material_costs ORDER BY item_name");
            $costs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $costs]);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (empty($input['item_name']) || empty($input['unit']) || !isset($input['price'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Item name, unit, and price are required.']);
                exit();
            }
            $stmt = $pdo->prepare("INSERT INTO material_costs (item_name, unit, price) VALUES (:item_name, :unit, :price)");
            $stmt->execute([
                ':item_name' => $input['item_name'],
                ':unit' => $input['unit'],
                ':price' => $input['price']
            ]);
            http_response_code(201);
            echo json_encode(['status' => 'success', 'message' => 'Material cost added.', 'id' => $pdo->lastInsertId()]);
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'ID is required.']);
                exit();
            }
            $stmt = $pdo->prepare("DELETE FROM material_costs WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['status' => 'success', 'message' => 'Material cost deleted.']);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Costs API error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
}
