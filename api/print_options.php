<?php
require_once 'db.php';
require_once 'config.php';
require_once 'is_admin.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true);
$type = $_GET['type'] ?? null;

try {
    switch ($method) {
        case 'GET':
            if ($type) {
                $stmt = $pdo->prepare("SELECT id, option_value FROM print_options WHERE option_type = ? AND is_active = TRUE ORDER BY option_value");
                $stmt->execute([$type]);
                $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'success', 'data' => $options]);
            } else {
                // All authenticated users can get the list for forms.
                $stmt = $pdo->query("SELECT id, option_type AS type, option_value AS value FROM print_options WHERE is_active = TRUE ORDER BY option_type, option_value");
                $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'success', 'data' => $options]);
            }
            break;

        case 'POST':
            if (!$is_admin) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                exit;
            }
            if (empty($data['option_type']) || empty($data['option_value'])) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Option type and value are required.']);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO print_options (option_type, option_value) VALUES (?, ?)");
            $stmt->execute([$data['option_type'], $data['option_value']]);
            echo json_encode(['status' => 'success', 'message' => 'Option added successfully.']);
            break;

        case 'DELETE':
            if (!$is_admin) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
                exit;
            }
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Option ID is required.']);
                exit;
            }
            $stmt = $pdo->prepare("DELETE FROM print_options WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'Option deleted successfully.']);
            break;

        default:
            http_response_code(405); // Method Not Allowed
            echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
