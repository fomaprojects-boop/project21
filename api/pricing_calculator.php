<?php
require_once 'db.php';
require_once 'config.php';
require_once 'calculate_price.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to use the pricing calculator.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'Client';

if ($method === 'POST') {
    $action = $_GET['action'] ?? 'save_quote';
    $input = json_decode(file_get_contents('php://input'), true);

    if ($action === 'calculate') {
        $price = calculatePrintingPrice(
            $input['size'] ?? 'A4',
            $input['material'] ?? 'Paper',
            $input['quantity'] ?? 1,
            $input['finishing_options'] ?? []
        );
        echo json_encode(['status' => 'success', 'calculated_price' => $price]);
        exit();
    }
    
    // This is for 'save_quote' action
    if (empty($input['size']) || empty($input['materials']) || empty($input['copies'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Size, materials, and copies are required fields.']);
        exit();
    }
    
    $size = htmlspecialchars(strip_tags($input['size']));
    $materials = htmlspecialchars(strip_tags($input['materials']));
    $copies = intval($input['copies']);
    $finishingOptions = isset($input['finishing_options']) ? (array)$input['finishing_options'] : [];
    
    $sanitizedFinishing = array_map(fn($option) => htmlspecialchars(strip_tags($option)), $finishingOptions);

    $totalPrice = calculatePrintingPrice($size, $materials, $copies, $sanitizedFinishing);
    
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO pricing_quotes (customer_id, size, materials, copies, finishing_options, total_price) 
             VALUES (:customer_id, :size, :materials, :copies, :finishing_options, :total_price)"
        );

        $finishingString = implode(', ', $sanitizedFinishing);

        $stmt->bindParam(':customer_id', $userId);
        $stmt->bindParam(':size', $size);
        $stmt->bindParam(':materials', $materials);
        $stmt->bindParam(':copies', $copies);
        $stmt->bindParam(':finishing_options', $finishingString);
        $stmt->bindParam(':total_price', $totalPrice);
        
        $stmt->execute();
        
        http_response_code(201);
        echo json_encode([
            'status' => 'success', 
            'message' => 'Quotation saved successfully.', 
            'quote_id' => $pdo->lastInsertId(),
            'calculated_price' => $totalPrice
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Saving pricing quote failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Could not save the quotation.']);
    }

} elseif ($method === 'GET') {
    try {
        if ($userRole === 'Admin' || $userRole === 'Staff') {
            $stmt = $pdo->prepare("SELECT q.*, u.full_name as customer_name FROM pricing_quotes q JOIN users u ON q.customer_id = u.id ORDER BY q.created_at DESC");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM pricing_quotes WHERE customer_id = :customer_id ORDER BY created_at DESC");
            $stmt->bindParam(':customer_id', $userId);
        }
        
        $stmt->execute();
        $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $quotes]);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Fetching pricing quotes failed: ". $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Could not retrieve quotations.']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
