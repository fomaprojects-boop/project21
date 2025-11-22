<?php
require_once 'config.php';
require_once 'db.php';

session_start();
header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'An unknown error occurred.'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!isset($_GET['job_order_id'])) {
            http_response_code(400);
            $response['message'] = 'Job Order ID is required.';
            echo json_encode($response);
            exit;
        }

        $jobOrderId = $_GET['job_order_id'];
        
        $stmt = $pdo->prepare("SELECT jc.*, u.full_name as user_name 
                               FROM job_order_communications jc 
                               LEFT JOIN users u ON jc.user_id = u.id
                               WHERE jc.job_order_id = ? 
                               ORDER BY jc.created_at ASC");
        $stmt->execute([$jobOrderId]);
        $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = ['status' => 'success', 'data' => $communications];

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $isPublic = isset($_GET['source']) && $_GET['source'] === 'public';
        
        if (!$isPublic && !isset($_SESSION['user_id'])) {
            http_response_code(401);
            $response['message'] = 'Authentication required.';
            echo json_encode($response);
            exit;
        }

        $jobOrderId = $_POST['job_order_id'];
        $message = isset($_POST['message']) ? trim($_POST['message']) : null;
        $attachment = isset($_FILES['attachment']) ? $_FILES['attachment'] : null;

        if (empty($message) && empty($attachment)) {
            http_response_code(400);
            $response['message'] = 'A message or an attachment is required.';
            echo json_encode($response);
            exit;
        }

        $userId = null;
        $customerName = null;
        
        if ($isPublic) {
            $token = $_POST['token'];
            $stmt = $pdo->prepare("SELECT c.name FROM job_orders jo JOIN customers c ON jo.customer_id = c.id WHERE jo.id = ? AND jo.public_token = ?");
            $stmt->execute([$jobOrderId, $token]);
            $customerName = $stmt->fetchColumn();
            if (!$customerName) {
                http_response_code(403);
                $response['message'] = 'Invalid token or Job Order ID.';
                echo json_encode($response);
                exit;
            }
        } else {
            $userId = $_SESSION['user_id'];
        }

        $attachmentPath = null;
        if ($attachment && $attachment['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/communications/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $fileName = time() . '_' . basename($attachment['name']);
            $destination = $uploadDir . $fileName;
            if (move_uploaded_file($attachment['tmp_name'], $destination)) {
                $attachmentPath = 'uploads/communications/' . $fileName;
            } else {
                throw new Exception('Failed to move uploaded file.');
            }
        }

        $sql = "INSERT INTO job_order_communications (job_order_id, user_id, customer_name, message, attachment_path) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$jobOrderId, $userId, $customerName, $message, $attachmentPath]);
        
        $response = ['status' => 'success', 'message' => 'Message sent successfully.'];
    }

} catch (PDOException $e) {
    http_response_code(500);
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    http_response_code(500);
    $response['message'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($response);
