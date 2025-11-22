<?php
require_once 'config.php';
require_once 'db.php';
require_once 'invoice_helper.php';

session_start();

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'An unknown error occurred.'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        $userRole = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : null;

        if (isset($_GET['job_order_id'])) {
            $stmt = $pdo->prepare("SELECT dp.*, jo.tracking_number 
                                   FROM digital_proofs dp
                                   JOIN job_orders jo ON dp.job_order_id = jo.id
                                   WHERE dp.job_order_id = ? ORDER BY dp.version DESC");
            $stmt->execute([$_GET['job_order_id']]);
        } else {
            if (!$userId) {
                http_response_code(401);
                $response['message'] = 'Authentication required.';
                echo json_encode($response);
                exit;
            }
            if ($userRole === 'Client') {
                $stmt = $pdo->prepare("SELECT dp.*, jo.tracking_number FROM digital_proofs dp JOIN job_orders jo ON dp.job_order_id = jo.id WHERE jo.customer_id = (SELECT customer_id FROM users WHERE id = ?) ORDER BY dp.created_at DESC");
                $stmt->execute([$userId]);
            } else {
                $stmt = $pdo->prepare("SELECT dp.*, jo.tracking_number FROM digital_proofs dp JOIN job_orders jo ON dp.job_order_id = jo.id ORDER BY dp.created_at DESC");
                $stmt->execute();
            }
        }
        
        $proofs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response = ['status' => 'success', 'data' => $proofs];

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_GET['action']) && $_GET['action'] === 'upload_proof') {
             if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['Admin', 'Staff'])) {
                http_response_code(403);
                $response['message'] = 'You do not have permission to perform this action.';
                echo json_encode($response);
                exit;
            }

            $jobOrderId = $_POST['job_order_id'];
            $file = $_FILES['proof_file'];

            $stmt = $pdo->prepare("SELECT COALESCE(MAX(version), 0) + 1 AS next_version FROM digital_proofs WHERE job_order_id = ?");
            $stmt->execute([$jobOrderId]);
            $nextVersion = $stmt->fetchColumn();

            $uploadDir = 'uploads/proofs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = "proof_" . $jobOrderId . "_v" . $nextVersion . "_" . time() . "." . $fileExtension;
            $filePath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                $stmt = $pdo->prepare("INSERT INTO digital_proofs (job_order_id, proof_path, version, status, created_by) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$jobOrderId, $filePath, $nextVersion, 'Pending Approval', $_SESSION['user_id']]);
                
                $pdo->prepare("UPDATE job_orders SET status = 'Pending Approval' WHERE id = ?")->execute([$jobOrderId]);
                
                // Add a record in the communication history
                $message = "Designer uploaded Proof Version {$nextVersion} for client approval.";
                $commStmt = $pdo->prepare("INSERT INTO job_order_communications (job_order_id, user_id, message) VALUES (?, ?, ?)");
                $commStmt->execute([$jobOrderId, $_SESSION['user_id'], $message]);

                $response = ['status' => 'success', 'message' => 'Proof uploaded successfully.'];
            } else {
                http_response_code(500);
                $response['message'] = 'Failed to upload file.';
            }

        } elseif (isset($_GET['action']) && $_GET['action'] === 'update_status') {
            $data = json_decode(file_get_contents('php://input'), true);
            $proofId = $data['proof_id'];
            $status = $data['status'];
            $clientComment = isset($data['client_comment']) ? $data['client_comment'] : null;
            $token = $data['token'];

            $stmt = $pdo->prepare("SELECT jo.id, jo.public_token, c.name as customer_name, dp.version
                                   FROM digital_proofs dp 
                                   JOIN job_orders jo ON dp.job_order_id = jo.id
                                   JOIN customers c ON jo.customer_id = c.id
                                   WHERE dp.id = ?");
            $stmt->execute([$proofId]);
            $jobInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($jobInfo && $jobInfo['public_token'] === $token) {
                $pdo->prepare("UPDATE digital_proofs SET status = ? WHERE id = ?")->execute([$status, $proofId]);
                
                $message = "Client has updated Proof Version {$jobInfo['version']} to: **{$status}**.";
                if ($status === 'Needs Fixing' && !empty($clientComment)) {
                    $pdo->prepare("UPDATE job_orders SET status = 'Designing' WHERE id = ?")->execute([$jobInfo['id']]);
                    $message .= "\n\n**Client's Comment:**\n*{$clientComment}*";
                } elseif ($status === 'Approved') {
                    $pdo->prepare("UPDATE job_orders SET status = 'Printing' WHERE id = ?")->execute([$jobInfo['id']]);
                }

                $commStmt = $pdo->prepare("INSERT INTO job_order_communications (job_order_id, customer_name, message) VALUES (?, ?, ?)");
                $commStmt->execute([$jobInfo['id'], $jobInfo['customer_name'], $message]);

                $response = ['status' => 'success', 'message' => 'Status updated successfully.'];
            } else {
                http_response_code(403);
                $response['message'] = 'Invalid token or proof ID.';
            }
        }
    }
} catch (PDOException $e) {
    http_response_code(500);
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    http_response_code(500);
    $response['message'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($response);
