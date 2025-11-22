<?php
require_once 'db.php';
require_once 'config.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // --- UPLOAD A NEW FILE FOR A JOB ORDER ---
    if (empty($_POST['job_order_id']) || empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Job Order ID and a file are required.']);
        exit();
    }

    $jobOrderId = intval($_POST['job_order_id']);
    $file = $_FILES['file'];

    // Basic Preflight Checks
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/postscript', 'image/vnd.adobe.photoshop'];
    $maxFileSize = 50 * 1024 * 1024; // 50 MB
    
    $fileType = mime_content_type($file['tmp_name']);
    $fileSize = $file['size'];
    $resolutionCheck = true; // Placeholder for resolution check

    $preflightStatus = 'Approved for Print';
    $preflightIssues = [];

    if (!in_array($fileType, $allowedTypes)) {
        $preflightStatus = 'Needs Fixing';
        $preflightIssues[] = "Invalid file type: {$fileType}.";
    }
    if ($fileSize > $maxFileSize) {
        $preflightStatus = 'Needs Fixing';
        $preflightIssues[] = "File size exceeds 50MB limit.";
    }
    if ($fileType === 'image/jpeg' || $fileType === 'image/png') {
        list($width, $height) = getimagesize($file['tmp_name']);
        if ($width < 300 || $height < 300) {
            $resolutionCheck = false;
            $preflightStatus = 'Needs Fixing';
            $preflightIssues[] = "Image resolution is too low (less than 300x300 pixels).";
        }
    }

    $uploadDir = '../uploads/job_files/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newFileName = 'job_' . $jobOrderId . '_' . uniqid() . '.' . $fileExtension;
    $destination = $uploadDir . $newFileName;

    if ($preflightStatus === 'Approved for Print' && !move_uploaded_file($file['tmp_name'], $destination)) {
         http_response_code(500);
         echo json_encode(['status' => 'error', 'message' => 'Failed to save the uploaded file.']);
         exit();
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO job_order_files (job_order_id, file_path, file_type, status) 
             VALUES (:job_order_id, :file_path, :file_type, :status)"
        );
        $stmt->bindParam(':job_order_id', $jobOrderId);
        $stmt->bindParam(':file_path', $destination);
        $stmt->bindParam(':file_type', $fileType);
        $stmt->bindParam(':status', $preflightStatus);
        $stmt->execute();
        
        http_response_code(201);
        echo json_encode([
            'status' => 'success', 
            'message' => 'File processed.',
            'file_id' => $pdo->lastInsertId(),
            'preflight_status' => $preflightStatus,
            'issues' => $preflightIssues
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("File upload DB entry failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error during file upload.']);
    }

} elseif ($method === 'PUT') {
    // --- UPDATE FILE STATUS (e.g., from Needs Fixing to Approved) ---
    $input = json_decode(file_get_contents('php://input'), true);
    $fileId = $input['file_id'] ?? null;
    $newStatus = $input['status'] ?? null;
    
    $userRole = $_SESSION['user_role'] ?? 'Client';
    if ($userRole !== 'Admin' && $userRole !== 'Staff') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'You do not have permission to change file status.']);
        exit();
    }

    if (!$fileId || !$newStatus || !in_array($newStatus, ['Approved for Print', 'Needs Fixing'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'File ID and a valid status are required.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE job_order_files SET status = :status WHERE id = :id");
        $stmt->bindParam(':status', $newStatus);
        $stmt->bindParam(':id', $fileId);
        $stmt->execute();

        echo json_encode(['status' => 'success', 'message' => "File status updated to '{$newStatus}'."]);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("File status update failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Database error while updating file status.']);
    }
} elseif ($method === 'GET') {
    // --- RETRIEVE UPLOADED FILES ---
    $userRole = $_SESSION['user_role'] ?? 'Client';
    $userId = $_SESSION['user_id'];

    try {
        if ($userRole === 'Admin' || $userRole === 'Staff') {
            // Admins/Staff can see all files, joined with job order info
            $stmt = $pdo->query(
                "SELECT f.*, jo.tracking_number 
                 FROM job_order_files f
                 JOIN job_orders jo ON f.job_order_id = jo.id
                 ORDER BY jo.created_at DESC"
            );
        } else {
            // Clients can only see files for their own job orders
            $stmt = $pdo->prepare(
                "SELECT f.*, jo.tracking_number 
                 FROM job_order_files f
                 JOIN job_orders jo ON f.job_order_id = jo.id
                 WHERE jo.customer_id = :customer_id
                 ORDER BY jo.created_at DESC"
            );
            $stmt->bindParam(':customer_id', $userId);
        }
        
        $stmt->execute();
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $files]);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Fetching uploaded files failed: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve file list.']);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
}
