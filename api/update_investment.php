<?php
// api/update_investment.php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

// Only Admins and Accountants can manage investments
require_role(['Admin', 'Accountant']);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

$investment_id = filter_var($data['investment_id'] ?? null, FILTER_VALIDATE_INT);
$new_quantity = filter_var($data['new_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
$new_purchase_cost = filter_var($data['new_purchase_cost'] ?? null, FILTER_VALIDATE_FLOAT);

if ($investment_id === false || $new_quantity === false || $new_quantity < 0 || $new_purchase_cost === false || $new_purchase_cost < 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Valid investment ID, non-negative quantity, and non-negative cost are required.']);
    exit();
}

try {
    // Check if investment exists
    $check_stmt = $pdo->prepare("SELECT id FROM investments WHERE id = ?");
    $check_stmt->execute([$investment_id]);
    if ($check_stmt->fetch() === false) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Investment not found.']);
        exit();
    }

    // Update the quantity and purchase cost
    $update_sql = "UPDATE investments SET quantity = ?, purchase_cost = ? WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$new_quantity, $new_purchase_cost, $investment_id]);

    echo json_encode(['status' => 'success', 'message' => 'Investment updated successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
