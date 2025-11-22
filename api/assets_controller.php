<?php
session_start();
header('Content-Type: application/json');

require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Handle fetching assets
        get_assets();
        break;
    case 'POST':
        // Handle creating a new asset
        create_asset();
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
        break;
}

function get_assets() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM assets ORDER BY purchase_date DESC");
        $stmt->execute();
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $assets]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch assets: ' . $e->getMessage()]);
    }
}

function create_asset() {
    global $pdo;
    $response = ['status' => 'error', 'message' => 'An unknown error occurred.'];

    try {
        // Get data from POST request
        $name = $_POST['name'] ?? null;
        $category = $_POST['category'] ?? null;
        $purchase_date = $_POST['purchase_date'] ?? null;
        $purchase_cost = $_POST['purchase_cost'] ?? null;
        $receipt_file = $_FILES['receipt'] ?? null;

        if (empty($name) || empty($category) || empty($purchase_date) || empty($purchase_cost)) {
            throw new Exception('Asset name, category, purchase date, and cost are required.');
        }

        $file_url = null;
        if ($receipt_file && $receipt_file['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/receipts/';
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    throw new Exception('Failed to create upload directory.');
                }
            }

            $file_extension = strtolower(pathinfo($receipt_file['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
            
            if (!in_array($file_extension, $allowed_extensions)) {
                throw new Exception('Invalid file type. Only PDF, JPG, and PNG are allowed.');
            }
            if ($receipt_file['size'] > 5000000) { // 5MB Limit
                throw new Exception('File is too large. Maximum 5MB allowed.');
            }

            $new_filename = 'asset_receipt_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;

            if (!move_uploaded_file($receipt_file['tmp_name'], $upload_path)) {
                throw new Exception('Failed to upload receipt file.');
            }
            
            $file_url = 'uploads/receipts/' . $new_filename;
        }

        // Insert into database
        $stmt = $pdo->prepare("INSERT INTO assets (name, category, purchase_date, purchase_cost, receipt_url) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $category, $purchase_date, $purchase_cost, $file_url]);
        $asset_id = $pdo->lastInsertId();

        // Add to audit trail
        $stmt = $pdo->prepare("INSERT INTO audit_trails (user_id, action, target_id, target_type) VALUES (?, 'create_asset', ?, 'asset')");
        $stmt->execute([$_SESSION['user_id'], $asset_id]);

        $response = ['status' => 'success', 'message' => 'Asset created successfully!'];

    } catch (Exception $e) {
        $response = ['status' => 'error', 'message' => $e->getMessage()];
        http_response_code(400);
    }

    echo json_encode($response);
}
?>
