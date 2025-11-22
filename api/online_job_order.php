<?php
require_once 'db.php';
require_once 'config.php';
require_once 'calculate_price.php';
require_once 'invoice_helper.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in to access job orders.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'Client'; // Default to client if role is not set

if ($method === 'POST') {
    // --- CREATE A NEW JOB ORDER WITH FILE UPLOADS ---
    
    if (empty($_POST['size']) || empty($_POST['quantity']) || empty($_POST['material'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Size, quantity, and material are required fields.']);
        exit();
    }
    
    // If admin is creating, use customer_id from form. Otherwise, use session user ID.
    $customerId = ($userRole === 'Admin' && !empty($_POST['customer_id'])) ? intval($_POST['customer_id']) : $userId;
    $size = htmlspecialchars(strip_tags($_POST['size']));
    $quantity = intval($_POST['quantity']);
    $material = htmlspecialchars(strip_tags($_POST['material']));
    $finishing = isset($_POST['finishing']) ? htmlspecialchars(strip_tags($_POST['finishing'])) : null;
    $notes = isset($_POST['notes']) ? htmlspecialchars(strip_tags($_POST['notes'])) : null;
    
    $trackingNumber = 'J' . time() . rand(100, 999);

    // Calculate cost and selling price
    $finishingOptions = $finishing ? explode(',', $finishing) : [];
    $sellingPrice = calculatePrintingPrice($size, $material, $quantity, $finishingOptions);
    // For this model, we'll assume cost price is 50% of selling price.
    // A more complex system would calculate this based on material_costs.
    $costPrice = $sellingPrice / 2;

    
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO job_orders (customer_id, tracking_number, size, quantity, material, finishing, notes, cost_price, selling_price) 
             VALUES (:customer_id, :tracking_number, :size, :quantity, :material, :finishing, :notes, :cost_price, :selling_price)"
        );

        $stmt->bindParam(':customer_id', $customerId);
        $stmt->bindParam(':tracking_number', $trackingNumber);
        $stmt->bindParam(':size', $size);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':material', $material);
        $stmt->bindParam(':finishing', $finishing);
        $stmt->bindParam(':notes', $notes);
        $stmt->bindParam(':cost_price', $costPrice);
        $stmt->bindParam(':selling_price', $sellingPrice);
        
        $stmt->execute();
        $jobOrderId = $pdo->lastInsertId();

        $uploadDir = '../uploads/job_orders/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/postscript', 'image/vnd.adobe.photoshop', 'application/x-zip-compressed'];
        $maxFileSize = 50 * 1024 * 1024; // 50 MB

        $uploadedFiles = [];
        if (!empty($_FILES['files'])) {
            foreach ($_FILES['files']['name'] as $key => $name) {
                if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES['files']['tmp_name'][$key];
                    $fileType = mime_content_type($tmpName);
                    $fileSize = $_FILES['files']['size'][$key];

                    if (!in_array($fileType, $allowedTypes)) {
                        throw new Exception("File type '$fileType' not allowed for file '$name'.");
                    }
                    if ($fileSize > $maxFileSize) {
                        throw new Exception("File '$name' exceeds the maximum size of 50MB.");
                    }
                    
                    $fileExtension = pathinfo($name, PATHINFO_EXTENSION);
                    $newFileName = uniqid('file_', true) . '.' . $fileExtension;
                    $destination = $uploadDir . $newFileName;

                    if (move_uploaded_file($tmpName, $destination)) {
                        $fileStmt = $pdo->prepare(
                            "INSERT INTO job_order_files (job_order_id, file_path, file_type) 
                             VALUES (:job_order_id, :file_path, :file_type)"
                        );
                        $fileStmt->bindParam(':job_order_id', $jobOrderId);
                        $fileStmt->bindParam(':file_path', $destination);
                        $fileStmt->bindParam(':file_type', $fileType);
                        $fileStmt->execute();
                        $uploadedFiles[] = $newFileName;
                    } else {
                        throw new Exception("Failed to move uploaded file '$name'.");
                    }
                }
            }
        }

        // --- AUTOMATED INVOICE CREATION ---
        $jobDetails = "Job Order #{$trackingNumber}: {$quantity} x {$size} {$material}";
        $invoiceId = createInvoiceForJobOrder($pdo, $jobOrderId, $customerId, $sellingPrice, $jobDetails);

        if (!$invoiceId) {
            throw new Exception("Failed to create an invoice for the job order.");
        }

        // --- AUTOMATED ASSIGNMENT AND STATUS UPDATE ---
        // 1. Find a random staff member to assign the job to
        $staffStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'Staff' ORDER BY RAND() LIMIT 1");
        $staffStmt->execute();
        $assignedToId = $staffStmt->fetchColumn();

        // Generate a unique public token
        $publicToken = bin2hex(random_bytes(16));

        // 2. Update the job order with the invoice_id, assigned_to, token, and new status
        $updateStmt = $pdo->prepare(
            "UPDATE job_orders SET invoice_id = :invoice_id, assigned_to = :assigned_to, public_token = :public_token, status = 'In Progress' WHERE id = :job_order_id"
        );
        $updateStmt->bindParam(':invoice_id', $invoiceId);
        $updateStmt->bindParam(':assigned_to', $assignedToId);
        $updateStmt->bindParam(':public_token', $publicToken);
        $updateStmt->bindParam(':job_order_id', $jobOrderId);
        $updateStmt->execute();

        // Commit the transaction after all operations are successful
        $pdo->commit();

        // Placeholder for sending notifications
        error_log("Notification: New job #{$trackingNumber} assigned to staff #{$assignedToId}. Invoice #{$invoiceId} created for customer #{$customerId}.");

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Job order created, invoice generated, and job assigned successfully.',
            'job_order_id' => $jobOrderId,
            'tracking_number' => $trackingNumber,
            'invoice_id' => $invoiceId,
            'assigned_to' => $assignedToId
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        error_log("Job order creation with files failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

} elseif ($method === 'GET') {
    // --- RETRIEVE JOB ORDERS ---
    try {
        if ($userRole === 'Admin' || $userRole === 'Staff') {
            // Admins and Staff can see all orders
            $stmt = $pdo->prepare("SELECT * FROM job_orders ORDER BY created_at DESC");
        } else {
            // Clients can only see their own orders
            $stmt = $pdo->prepare("SELECT * FROM job_orders WHERE customer_id = :customer_id ORDER BY created_at DESC");
            $stmt->bindParam(':customer_id', $userId);
        }
        
        $stmt->execute();
        $jobOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $jobOrders]);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Fetching job orders failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve job orders.']);
    }

} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
